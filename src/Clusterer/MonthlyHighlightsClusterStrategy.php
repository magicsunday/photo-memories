<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds a highlight memory for each (year, month) with sufficient coverage.
 */
final class MonthlyHighlightsClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 40,
        private readonly int $minDistinctDays = 10
    ) {
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
        if ($this->minDistinctDays < 1) {
            throw new \InvalidArgumentException('minDistinctDays must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'monthly_highlights';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, list<Media>> $byYm */
        $byYm = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $ym = $local->format('Y-m');
            $byYm[$ym] ??= [];
            $byYm[$ym][] = $m;
        }

        $eligibleMonths = \array_filter(
            $byYm,
            function (array $list) use ($tz): bool {
                if (\count($list) < $this->minItems) {
                    return false;
                }

                /** @var array<string,bool> $days */
                $days = [];
                foreach ($list as $m) {
                    $days[$m->getTakenAt()->setTimezone($tz)->format('Y-m-d')] = true;
                }

                $count = \count($days);
                if ($count < $this->minDistinctDays) {
                    return false;
                }

                return true;
            }
        );

        if ($eligibleMonths === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleMonths as $ym => $list) {
            \usort($list, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $year  = (int) \substr($ym, 0, 4);
            $month = (int) \substr($ym, 5, 2);
            $label = self::germanMonthLabel($month) . ' ' . $year;

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'year'       => $year,
                    'month'      => $month,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }

    private static function germanMonthLabel(int $m): string
    {
        return match ($m) {
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
            default => 'Monat',
        };
    }
}
