<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

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
        private readonly string $timezone,
    ) {
        if ($this->algorithm === '') {
            throw new \InvalidArgumentException('algorithm must not be empty.');
        }
        if ($this->homeRadiusMeters <= 0.0) {
            throw new \InvalidArgumentException('homeRadiusMeters must be > 0.');
        }
        if ($this->minHomeShare < 0.0 || $this->minHomeShare > 1.0) {
            throw new \InvalidArgumentException('minHomeShare must be within 0..1.');
        }
        if ($this->minItemsPerDay < 1) {
            throw new \InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
        if ($this->minItemsTotal < 1) {
            throw new \InvalidArgumentException('minItemsTotal must be >= 1.');
        }
        $lookup = [];
        foreach ($allowedWeekdays as $dow) {
            $dow = (int) $dow;
            if ($dow >= 1 && $dow <= 7) {
                $lookup[$dow] = true;
            }
        }

        if ($lookup === []) {
            throw new \InvalidArgumentException('allowedWeekdays must contain at least one weekday between 1 and 7.');
        }

        $this->allowedWeekdayLookup = $lookup;
    }

    final public function name(): string
    {
        return $this->algorithm;
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        if ($this->homeLat === null || $this->homeLon === null || $this->allowedWeekdayLookup === []) {
            return [];
        }

        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($timestamped as $media) {
            $takenAt = $media->getTakenAt();
            \assert($takenAt instanceof DateTimeImmutable);

            $local = $takenAt->setTimezone($tz);
            $dow = (int) $local->format('N');
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
                if ($lat === null || $lon === null) {
                    continue;
                }

                $distance = MediaMath::haversineDistanceInMeters(
                    (float) $lat,
                    (float) $lon,
                    (float) $this->homeLat,
                    (float) $this->homeLon,
                );

                if ($distance <= $this->homeRadiusMeters) {
                    $within[] = $media;
                }
            }

            if ($within === []) {
                continue;
            }

            $share = \count($within) / (float) \count($list);
            if ($share >= $this->minHomeShare) {
                $homeOnly[$day] = $within;
                $keepDays[] = $day;
            }
        }

        if ($keepDays === []) {
            return [];
        }

        \sort($keepDays, \SORT_STRING);

        /** @var list<ClusterDraft> $clusters */
        $clusters = [];
        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$clusters, $homeOnly): void {
            if (\count($run) === 0) {
                return;
            }

            /** @var list<Media> $members */
            $members = [];
            foreach ($run as $day) {
                foreach ($homeOnly[$day] as $media) {
                    $members[] = $media;
                }
            }

            if (\count($members) < $this->minItemsTotal) {
                $run = [];
                return;
            }

            $centroid = MediaMath::centroid($members);
            $timeRange = MediaMath::timeRange($members);

            $clusters[] = new ClusterDraft(
                algorithm: $this->algorithm,
                params: [
                    'time_range' => $timeRange,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $media): int => $media->getId(), $members),
            );

            $run = [];
        };

        $prev = null;
        foreach ($keepDays as $day) {
            if ($prev !== null && !$this->isNextDay($prev, $day)) {
                $flush();
            }

            $run[] = $day;
            $prev = $day;
        }

        $flush();

        return $clusters;
    }
}
