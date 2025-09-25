<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Collects all photos taken around today's month/day across different years.
 * Example: Feb-14 across 2014..2025 within a +/- window of days.
 */
final class OnThisDayOverYearsClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $windowDays = 0,   // 0 = exact same month/day, 1..3 = tolerant
        private readonly int $minYears   = 3,
        private readonly int $minItems   = 12
    ) {
        if ($this->windowDays < 0) {
            throw new \InvalidArgumentException('windowDays must be >= 0.');
        }
        if ($this->minYears < 1) {
            throw new \InvalidArgumentException('minYears must be >= 1.');
        }
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'on_this_day_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
        $anchorMonth = (int) $now->format('n');
        $anchorDay   = (int) $now->format('j');

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($now->getTimezone());
            $y = (int) $local->format('Y');
            $mdDist = $this->monthDayDistance($anchorMonth, $anchorDay, (int) $local->format('n'), (int) $local->format('j'));
            if ($mdDist <= $this->windowDays) {
                $picked[] = $m;
                $years[$y] = true;
            }
        }

        if (\count($picked) < $this->minItems || \count($years) < $this->minYears) {
            return [];
        }

        \usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $centroid = MediaMath::centroid($picked);
        $time     = MediaMath::timeRange($picked);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                    'years'      => \array_values(\array_keys($years)),
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $picked)
            ),
        ];
    }

    private function monthDayDistance(int $m1, int $d1, int $m2, int $d2): int
    {
        // Simple absolute distance in days ignoring leap-year wrap; good enough for small windows.
        $a = \strtotime(\sprintf('2001-%02d-%02d', $m1, $d1));
        $b = \strtotime(\sprintf('2001-%02d-%02d', $m2, $d2));
        if ($a === false || $b === false) {
            return 9999;
        }
        return (int) \abs(($b - $a) / 86400);
    }
}
