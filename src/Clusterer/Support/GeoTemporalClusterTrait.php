<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_merge;
use function array_values;
use function count;
use function max;
use function usort;

/**
 * Provides shared helpers to build geo-temporal media buckets.
 */
trait GeoTemporalClusterTrait
{
    /**
     * @param list<Media> $items
     *
     * @return array<string, list<Media>>
     */
    private function partitionByDay(array $items): array
    {
        /** @var array<string, list<Media>> $scopes */
        $scopes = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $day = $takenAt->format('Y-m-d');
            $scopes[$day] ??= [];
            $scopes[$day][] = $media;
        }

        return $scopes;
    }

    /**
     * @param list<Media> $items
     *
     * @return list<list<Media>>
     */
    private function buildGeoTemporalBuckets(
        array $items,
        GeoDbscanHelper $dbscanHelper,
        int $minMembers,
        float $radiusMeters,
        int $windowSeconds,
    ): array {
        if ($items === []) {
            return [];
        }

        $radiusMeters  = max(1.0, $radiusMeters);
        $windowSeconds = max(1, $windowSeconds);
        $minMembers    = max(1, $minMembers);

        $buckets = [];

        foreach ($this->partitionByDay($items) as $dayItems) {
            $dayBuckets = $this->clusterDayScope(
                $dayItems,
                $dbscanHelper,
                $minMembers,
                $radiusMeters,
                $windowSeconds,
            );

            if ($dayBuckets === []) {
                continue;
            }

            $buckets = array_merge($buckets, $dayBuckets);
        }

        if ($buckets === []) {
            return [];
        }

        usort(
            $buckets,
            static function (array $a, array $b): int {
                $aStart = $a[0]->getTakenAt();
                $bStart = $b[0]->getTakenAt();

                $aTs = $aStart instanceof DateTimeImmutable ? $aStart->getTimestamp() : 0;
                $bTs = $bStart instanceof DateTimeImmutable ? $bStart->getTimestamp() : 0;

                return $aTs <=> $bTs;
            }
        );

        return array_values($buckets);
    }

    /**
     * @param list<Media> $dayItems
     *
     * @return list<list<Media>>
     */
    private function clusterDayScope(
        array $dayItems,
        GeoDbscanHelper $dbscanHelper,
        int $minMembers,
        float $radiusMeters,
        int $windowSeconds,
    ): array {
        $timestamped = $this->filterTimestampedItems($dayItems);
        if ($timestamped === []) {
            return [];
        }

        /** @var list<Media> $gpsItems */
        $gpsItems = $this->filterTimestampedGpsItems($timestamped);
        /** @var array<int, bool> $clusteredIds */
        $clusteredIds = [];
        $buckets      = [];

        if ($gpsItems !== []) {
            $result = $dbscanHelper->clusterMedia(
                $gpsItems,
                $radiusMeters / 1000.0,
                $minMembers,
            );

            foreach ($result['clusters'] as $clusterMembers) {
                $windows = $this->buildWindowBuckets(
                    $clusterMembers,
                    $windowSeconds,
                    $minMembers,
                    $radiusMeters,
                    false,
                );

                foreach ($windows as $windowMembers) {
                    foreach ($windowMembers as $member) {
                        $clusteredIds[$member->getId()] = true;
                    }

                    $buckets[] = $windowMembers;
                }
            }
        }

        /** @var list<Media> $leftoverGps */
        $leftoverGps = [];
        foreach ($timestamped as $media) {
            if (isset($clusteredIds[$media->getId()])) {
                continue;
            }

            if ($media->getGpsLat() === null || $media->getGpsLon() === null) {
                continue;
            }

            $leftoverGps[] = $media;
        }

        if ($leftoverGps !== []) {
            $fallback = $this->buildWindowBuckets(
                $leftoverGps,
                $windowSeconds,
                $minMembers,
                $radiusMeters,
                true,
            );

            foreach ($fallback as $windowMembers) {
                foreach ($windowMembers as $member) {
                    $clusteredIds[$member->getId()] = true;
                }

                $buckets[] = $windowMembers;
            }
        }

        return $buckets;
    }

    /**
     * @param list<Media> $items
     *
     * @return list<list<Media>>
     */
    private function buildWindowBuckets(
        array $items,
        int $windowSeconds,
        int $minMembers,
        float $radiusMeters,
        bool $enforceDistance,
    ): array {
        if ($items === []) {
            return [];
        }

        usort(
            $items,
            static fn (Media $a, Media $b): int => ($a->getTakenAt()?->getTimestamp() ?? 0)
                <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<list<Media>> $buckets */
        $buckets = [];
        /** @var list<Media> $bucket */
        $bucket = [];
        $startTs = null;
        $anchorLat = null;
        $anchorLon = null;

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $ts  = $takenAt->getTimestamp();
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();

            if ($bucket === []) {
                if ($enforceDistance && ($lat === null || $lon === null)) {
                    continue;
                }

                $bucket    = [$media];
                $startTs   = $ts;
                $anchorLat = $lat;
                $anchorLon = $lon;
                continue;
            }

            if ($startTs === null) {
                $bucket    = [$media];
                $startTs   = $ts;
                $anchorLat = $lat;
                $anchorLon = $lon;
                continue;
            }

            $withinWindow = ($ts - $startTs) <= $windowSeconds;
            $withinRadius = true;

            if ($enforceDistance) {
                if ($anchorLat === null || $anchorLon === null || $lat === null || $lon === null) {
                    $withinRadius = false;
                } else {
                    $withinRadius = MediaMath::haversineDistanceInMeters(
                        $anchorLat,
                        $anchorLon,
                        $lat,
                        $lon,
                    ) <= $radiusMeters;
                }
            }

            if ($withinWindow && $withinRadius) {
                $bucket[] = $media;
                continue;
            }

            if (count($bucket) >= $minMembers) {
                $buckets[] = $bucket;
            }

            if ($enforceDistance && ($lat === null || $lon === null)) {
                $bucket    = [];
                $startTs   = null;
                $anchorLat = null;
                $anchorLon = null;
                continue;
            }

            $bucket    = [$media];
            $startTs   = $ts;
            $anchorLat = $lat;
            $anchorLon = $lon;
        }

        if ($bucket !== [] && count($bucket) >= $minMembers) {
            $buckets[] = $bucket;
        }

        return $buckets;
    }
}
