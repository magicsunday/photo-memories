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
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use MagicSunday\Memories\Utility\CalendarFeatureHelper;

use function array_map;
use function assert;
use function count;
use function sort;
use function hash;
use function hash_equals;
use function sprintf;

use const SORT_STRING;

/**
 * Shared implementation for at-home day clustering strategies.
 */
abstract class AbstractAtHomeClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    /**
     * @var array<int, true>
     */
    private readonly array $allowedWeekdayLookup;

    private ?string $expectedHomeConfigHash = null;

    /**
     * @param list<int> $allowedWeekdays
     */
    public function __construct(
        private readonly string $algorithm,
        array $allowedWeekdays,
        private readonly ?float $homeLat,
        private readonly ?float $homeLon,
        private readonly float $homeRadiusMeters,
        private readonly float $minHomeShare,
        private readonly int $minItemsPerDay,
        private readonly int $minItemsTotal,
        private readonly LocalTimeHelper $localTimeHelper,
        private readonly string $homeVersionHash = '',
    ) {
        if ($this->algorithm === '') {
            throw new InvalidArgumentException('algorithm must not be empty.');
        }

        if ($this->homeRadiusMeters <= 0.0) {
            throw new InvalidArgumentException('homeRadiusMeters must be > 0.');
        }

        if ($this->minHomeShare < 0.0 || $this->minHomeShare > 1.0) {
            throw new InvalidArgumentException('minHomeShare must be within 0..1.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }

        $lookup = [];
        foreach ($allowedWeekdays as $dow) {
            $dow = (int) $dow;
            if ($dow >= 1 && $dow <= 7) {
                $lookup[$dow] = true;
            }
        }

        if ($lookup === []) {
            throw new InvalidArgumentException('allowedWeekdays must contain at least one weekday between 1 and 7.');
        }

        $this->allowedWeekdayLookup = $lookup;
    }

    final public function name(): string
    {
        return $this->algorithm;
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        if ($this->homeLat === null || $this->homeLon === null || $this->allowedWeekdayLookup === []) {
            return [];
        }

        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($timestamped as $media) {
            $local = $this->localTimeHelper->resolve($media);
            assert($local instanceof DateTimeImmutable);
            $dow   = (int) $local->format('N');
            if (!isset($this->allowedWeekdayLookup[$dow])) {
                continue;
            }

            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $media;
        }

        if ($byDay === []) {
            return [];
        }

        /** @var array<string, list<Media>> $homeOnly */
        $homeOnly = [];
        /** @var list<string> $keepDays */
        $keepDays = [];

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        foreach ($eligibleDays as $day => $list) {
            $within = [];
            foreach ($list as $media) {
                $lat = $media->getGpsLat();
                $lon = $media->getGpsLon();
                if ($lat === null) {
                    continue;
                }

                if ($lon === null) {
                    continue;
                }

                $distanceKm = $this->distanceKmFromHome($media, (float) $lat, (float) $lon);

                if ($distanceKm <= $this->homeRadiusMeters / 1000.0) {
                    $within[] = $media;
                }
            }

            if ($within === []) {
                continue;
            }

            $share = count($within) / (float) count($list);
            if ($share >= $this->minHomeShare) {
                $homeOnly[$day] = $within;
                $keepDays[]     = $day;
            }
        }

        if ($keepDays === []) {
            return [];
        }

        sort($keepDays, SORT_STRING);

        /** @var list<ClusterDraft> $clusters */
        $clusters = [];
        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$clusters, $homeOnly): void {
            if ($run === []) {
                return;
            }

            /** @var list<Media> $members */
            $members = [];
            foreach ($run as $day) {
                foreach ($homeOnly[$day] as $media) {
                    $members[] = $media;
                }
            }

            if (count($members) < $this->minItemsTotal) {
                $run = [];

                return;
            }

            $centroid  = MediaMath::centroid($members);
            $timeRange = MediaMath::timeRange($members);

            $params = ['time_range' => $timeRange];

            $calendar = CalendarFeatureHelper::summarize($members);
            if ($calendar['isWeekend'] !== null) {
                $params['isWeekend'] = $calendar['isWeekend'];
            }

            if ($calendar['holidayId'] !== null) {
                $params['holidayId'] = $calendar['holidayId'];
            }

            $clusters[] = new ClusterDraft(
                algorithm: $this->algorithm,
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $media): int => $media->getId(), $members),
            );

            $run = [];
        };

        $prev = null;
        foreach ($keepDays as $day) {
            if ($prev !== null && !$this->isNextDay($prev, $day)) {
                $flush();
            }

            $run[] = $day;
            $prev  = $day;
        }

        $flush();

        return $clusters;
    }

    private function distanceKmFromHome(Media $media, float $lat, float $lon): float
    {
        $cachedDistance = $media->getDistanceKmFromHome();
        if ($cachedDistance !== null && $this->isHomeConfigFresh($media)) {
            return $cachedDistance;
        }

        $distanceMeters = MediaMath::haversineDistanceInMeters(
            $lat,
            $lon,
            $this->homeLat,
            $this->homeLon,
        );

        return $distanceMeters / 1000.0;
    }

    private function isHomeConfigFresh(Media $media): bool
    {
        $expectedHash = $this->computeHomeConfigHash();
        if ($expectedHash === null) {
            return false;
        }

        $currentHash = $media->getHomeConfigHash();
        if ($currentHash === null) {
            return false;
        }

        return hash_equals($expectedHash, $currentHash);
    }

    private function computeHomeConfigHash(): ?string
    {
        if ($this->homeLat === null || $this->homeLon === null) {
            return null;
        }

        if ($this->expectedHomeConfigHash !== null) {
            return $this->expectedHomeConfigHash;
        }

        $this->expectedHomeConfigHash = hash(
            'sha256',
            sprintf(
                '%.8f|%.8f|%.8f|%s',
                $this->homeLat,
                $this->homeLon,
                $this->homeRadiusMeters / 1000.0,
                $this->homeVersionHash,
            ),
        );

        return $this->expectedHomeConfigHash;
    }
}
