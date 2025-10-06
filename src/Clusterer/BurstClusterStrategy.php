<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_filter;
use function array_values;
use function assert;
use function count;
use function usort;

/**
 * Groups items captured within a short time & small spatial window.
 * Typical for bursts/series shots.
 */
final readonly class BurstClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;

    public function __construct(
        private LocationHelper $locationHelper,
        private int $maxGapSeconds = 90,
        private float $maxMoveMeters = 50.0,
        // Minimum photos per burst run before emitting a memory.
        private int $minItemsPerBurst = 3,
    ) {
        if ($this->maxGapSeconds < 1) {
            throw new InvalidArgumentException('maxGapSeconds must be >= 1.');
        }

        if ($this->maxMoveMeters < 0.0) {
            throw new InvalidArgumentException('maxMoveMeters must be >= 0.');
        }

        if ($this->minItemsPerBurst < 1) {
            throw new InvalidArgumentException('minItemsPerBurst must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'burst';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        $n = count($timestamped);
        if ($n < $this->minItemsPerBurst) {
            return [];
        }

        usort(
            $timestamped,
            static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt()
        );

        /** @var array<string, list<Media>> $metadataBuckets */
        $metadataBuckets = [];
        /** @var list<Media> $ungrouped */
        $ungrouped = [];

        foreach ($timestamped as $media) {
            $burstUuid = $media->getBurstUuid();
            if ($burstUuid !== null) {
                $metadataBuckets[$burstUuid] ??= [];
                $metadataBuckets[$burstUuid][] = $media;
                continue;
            }

            $ungrouped[] = $media;
        }

        $drafts = [];

        foreach ($this->filterGroupsByMinItems($metadataBuckets, $this->minItemsPerBurst) as $burstUuid => $members) {
            $drafts[] = $this->makeDraft($members, $burstUuid);
        }

        foreach ($this->buildHeuristicSessions($ungrouped) as $members) {
            $drafts[] = $this->makeDraft($members, null);
        }

        usort(
            $drafts,
            static function (ClusterDraft $a, ClusterDraft $b): int {
                $aRange = $a->getParams()['time_range'] ?? null;
                $bRange = $b->getParams()['time_range'] ?? null;

                if ($aRange === null || $bRange === null) {
                    return 0;
                }

                return ($aRange['from'] ?? 0) <=> ($bRange['from'] ?? 0);
            }
        );

        return $drafts;
    }

    /**
     * @param list<Media> $members
     */
    private function makeDraft(array $members, ?string $burstUuid): ClusterDraft
    {
        $orderedMembers = $this->sortMembers($members);

        $representatives = array_values(array_filter(
            $orderedMembers,
            static fn (Media $media): bool => $media->isBurstRepresentative() === true
        ));

        $centroidSource = $representatives !== [] ? $representatives : $orderedMembers;
        $centroid       = $this->computeCentroid($centroidSource);

        $representative = $representatives[0] ?? null;

        $params = [
            'time_range'              => $this->computeTimeRange($orderedMembers),
            'representative_media_id' => $representative?->getId(),
        ];

        if ($burstUuid !== null) {
            $params['burst_uuid'] = $burstUuid;
        }

        $tags = $this->collectDominantTags($orderedMembers);
        if ($tags !== []) {
            $params = [...$params, ...$tags];
        }

        $params = $this->appendLocationMetadata($orderedMembers, $params);

        $peopleParams = $this->buildPeopleParams($orderedMembers);
        $params       = [...$params, ...$peopleParams];

        return new ClusterDraft(
            algorithm: $this->name(),
            params: $params,
            centroid: ['lat' => $centroid['lat'], 'lon' => $centroid['lon']],
            members: $this->toMemberIds($orderedMembers)
        );
    }

    /**
     * @param list<Media> $items
     *
     * @return list<list<Media>>
     */
    private function buildHeuristicSessions(array $items): array
    {
        $count = count($items);
        if ($count === 0) {
            return [];
        }

        /** @var list<list<Media>> $sessions */
        $sessions = [];
        /** @var list<Media> $current */
        $current = [$items[0]];

        for ($i = 1; $i < $count; ++$i) {
            $media = $items[$i];
            $prev  = $items[$i - 1];

            $currTakenAt = $media->getTakenAt();
            $prevTakenAt = $prev->getTakenAt();
            assert($currTakenAt instanceof DateTimeImmutable);
            assert($prevTakenAt instanceof DateTimeImmutable);

            $timeOk = MediaMath::secondsBetween($currTakenAt, $prevTakenAt) <= $this->maxGapSeconds;

            $distOk = true;
            $lat1   = $prev->getGpsLat();
            $lon1   = $prev->getGpsLon();
            $lat2   = $media->getGpsLat();
            $lon2   = $media->getGpsLon();

            if ($lat1 !== null && $lon1 !== null && $lat2 !== null && $lon2 !== null) {
                $distOk = MediaMath::haversineDistanceInMeters($lat1, $lon1, $lat2, $lon2) <= $this->maxMoveMeters;
            }

            if ($timeOk && $distOk) {
                $current[] = $media;
                continue;
            }

            $sessions[] = $current;
            $current    = [$media];
        }

        if ($current !== []) {
            $sessions[] = $current;
        }

        return $this->filterListsByMinItems($sessions, $this->minItemsPerBurst);
    }

    /**
     * @param list<Media> $members
     *
     * @return list<Media>
     */
    private function sortMembers(array $members): array
    {
        usort(
            $members,
            static function (Media $a, Media $b): int {
                $repPriorityA = $a->isBurstRepresentative() === true ? 0 : 1;
                $repPriorityB = $b->isBurstRepresentative() === true ? 0 : 1;

                if ($repPriorityA !== $repPriorityB) {
                    return $repPriorityA <=> $repPriorityB;
                }

                $indexA = $a->getBurstIndex();
                $indexB = $b->getBurstIndex();

                if ($indexA !== null && $indexB !== null && $indexA !== $indexB) {
                    return $indexA <=> $indexB;
                }

                $takenA = $a->getTakenAt();
                $takenB = $b->getTakenAt();

                if ($takenA instanceof DateTimeImmutable && $takenB instanceof DateTimeImmutable) {
                    $timeCompare = $takenA->getTimestamp() <=> $takenB->getTimestamp();
                    if ($timeCompare !== 0) {
                        return $timeCompare;
                    }
                }

                return $a->getId() <=> $b->getId();
            }
        );

        return $members;
    }
}
