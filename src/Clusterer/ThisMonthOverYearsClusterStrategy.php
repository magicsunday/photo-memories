<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Aggregates all items from the current month across different years.
 */
final class ThisMonthOverYearsClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minYears = 3,
        private readonly int $minItems = 24,
        private readonly int $minDistinctDays = 8
    ) {
    }

    public function name(): string
    {
        return 'this_month_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz   = new DateTimeZone($this->timezone);
        $now  = new DateTimeImmutable('now', $tz);
        $mon  = (int) $now->format('n');

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];
        /** @var array<string,bool> $days */
        $days = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            if ((int) $local->format('n') !== $mon) {
                continue;
            }
            $picked[] = $m;
            $years[(int) $local->format('Y')] = true;
            $days[$local->format('Y-m-d')]    = true;
        }

        if (\count($picked) < $this->minItems || \count($years) < $this->minYears || \count($days) < $this->minDistinctDays) {
            return [];
        }

        \usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $centroid = MediaMath::centroid($picked);
        $time     = MediaMath::timeRange($picked);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'month'      => $mon,
                    'years'      => \array_values(\array_keys($years)),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $picked)
            ),
        ];
    }
}
