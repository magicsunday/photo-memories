<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;

/**
 * Multi-day camping runs (consecutive days) using keywords.
 */
final readonly class CampingTripClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    public function __construct(
        private string $timezone = 'Europe/Berlin',
        private int $minItemsPerDay = 3,
        private int $minNights = 2,
        private int $maxNights = 14,
        private int $minItemsTotal = 20
    ) {
        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minNights < 1) {
            throw new InvalidArgumentException('minNights must be >= 1.');
        }

        if ($this->maxNights < 1) {
            throw new InvalidArgumentException('maxNights must be >= 1.');
        }

        if ($this->maxNights < $this->minNights) {
            throw new InvalidArgumentException('maxNights must be >= minNights.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'camping_trip';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        $campingItems = $this->filterTimestampedItemsBy(
            $items,
            fn (Media $m): bool => $this->looksCamping(\strtolower($m->getPath()))
        );

        foreach ($campingItems as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);

            $d = $t->setTimezone($tz)->format('Y-m-d');
            $byDay[$d] ??= [];
            $byDay[$d][] = $m;
        }

        if ($byDay === []) {
            return [];
        }

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        if ($eligibleDays === []) {
            return [];
        }

        $days = \array_keys($eligibleDays);
        \sort($days, \SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<string> $run */
        $run = [];
        $prev = null;

        $flush = function () use (&$run, &$out, $eligibleDays): void {
            if ($run === []) {
                return;
            }

            $nights = \count($run) - 1;
            if ($nights < $this->minNights || $nights > $this->maxNights) {
                $run = [];
                return;
            }

            /** @var list<Media> $members */
            $members = [];
            foreach ($run as $d) {
                foreach ($eligibleDays[$d] as $m) {
                    $members[] = $m;
                }
            }

            if (\count($members) < $this->minItemsTotal) {
                $run = [];
                return;
            }

            \usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: 'camping_trip',
                params: [
                    'nights'     => $nights,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );

            $run = [];
        };

        foreach ($days as $d) {
            if ($prev !== null && !$this->isNextDay($prev, $d)) {
                $flush();
            }

            $run[] = $d;
            $prev  = $d;
        }

        $flush();

        return $out;
    }

    private function looksCamping(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['camping', 'zelt', 'zelten', 'wohnmobil', 'caravan', 'wohnwagen', 'campground', 'camp site', 'campsite', 'stellplatz'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }

        return false;
    }
}
