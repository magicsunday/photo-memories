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
 * Shared implementation for strategies that pick the strongest keyword-based day per year.
 */
abstract class KeywordBestDayOverYearsStrategy implements ClusterStrategyInterface
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        private readonly string $timezone,
        private readonly int $minItemsPerDay,
        private readonly int $minYears,
        private readonly int $minItemsTotal,
        private readonly array $keywords
    ) {
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

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable || !$this->matchesMedia($media)) {
                continue;
            }

            $local = $takenAt->setTimezone($tz);
            $year  = (int) $local->format('Y');
            $day   = $local->format('Y-m-d');

            $byYearDay[$year] ??= [];
            $byYearDay[$year][$day] ??= [];
            $byYearDay[$year][$day][] = $media;
        }

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];

        foreach ($byYearDay as $year => $days) {
            $bestDay = null;
            $bestCount = 0;

            foreach ($days as $day => $list) {
                $count = \count($list);
                if ($count >= $this->minItemsPerDay && $count > $bestCount) {
                    $bestCount = $count;
                    $bestDay = $day;
                }
            }

            if ($bestDay === null) {
                continue;
            }

            foreach ($days[$bestDay] as $media) {
                $picked[] = $media;
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
                params: $this->buildParams($years, $time),
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $media): int => $media->getId(), $picked)
            ),
        ];
    }

    abstract public function name(): string;

    /**
     * @param array<int,bool> $years
     * @param array{from:int,to:int} $timeRange
     * @return array<string,mixed>
     */
    protected function buildParams(array $years, array $timeRange): array
    {
        return [
            'years'      => \array_values(\array_keys($years)),
            'time_range' => $timeRange,
        ];
    }

    protected function matchesMedia(Media $media): bool
    {
        return $this->pathContainsKeyword($media->getPath());
    }

    private function pathContainsKeyword(string $path): bool
    {
        $lower = \strtolower($path);

        foreach ($this->keywords as $keyword) {
            if (\str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
