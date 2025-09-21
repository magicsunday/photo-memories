<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best "beach day" per year (based on filename keywords) and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 63])]
final class BeachOverYearsClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 6,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 24
    ) {
    }

    public function name(): string
    {
        return 'beach_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<int, array<string, list<Media>>> $byYearDay */
        $byYearDay = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            $path = \strtolower($m->getPath());
            if (!$t instanceof DateTimeImmutable || !$this->looksBeach($path)) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $y = (int) $local->format('Y');
            $d = $local->format('Y-m-d');
            $byYearDay[$y] ??= [];
            $byYearDay[$y][$d] ??= [];
            $byYearDay[$y][$d][] = $m;
        }

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];

        foreach ($byYearDay as $year => $days) {
            // pick day with most items
            $bestDay = null;
            $bestCnt = 0;
            foreach ($days as $d => $list) {
                $c = \count($list);
                if ($c >= $this->minItemsPerDay && $c > $bestCnt) {
                    $bestCnt = $c;
                    $bestDay = $d;
                }
            }
            if ($bestDay === null) {
                continue;
            }
            foreach ($days[$bestDay] as $m) {
                $picked[] = $m;
            }
            $years[$year] = true;
        }

        if (\count($years) < $this->minYears || \count($picked) < $this->minItemsTotal) {
            return [];
        }

        \usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
        $centroid = MediaMath::centroid($picked);
        $time     = MediaMath::timeRange($picked);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'years'      => \array_values(\array_keys($years)),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $picked)
            ),
        ];
    }

    private function looksBeach(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['strand', 'beach', 'meer', 'ocean', 'küste', 'kueste', 'coast', 'seaside', 'baltic', 'ostsee', 'nordsee', 'adriatic'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
