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
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function count;
use function str_contains;
use function strtolower;
use function usort;

/**
 * Shared implementation for strategies that pick the strongest keyword-based day per year.
 */
abstract class KeywordBestDayOverYearsStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    /**
     * @param list<string> $keywords
     */
    public function __construct(
        private readonly string $timezone,
        private readonly int $minItemsPerDay,
        private readonly int $minYears,
        private readonly int $minItemsTotal,
        private readonly array $keywords,
    ) {
        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minYears < 1) {
            throw new InvalidArgumentException('minYears must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }

        if ($this->keywords === []) {
            throw new InvalidArgumentException('keywords must not be empty.');
        }
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<int, array<string, list<Media>>> $byYearDay */
        $byYearDay = [];

        foreach ($this->filterTimestampedItemsBy(
            $items,
            fn (Media $media): bool => $this->matchesMedia($media)
        ) as $media) {
            $takenAt = $media->getTakenAt();
            assert($takenAt instanceof DateTimeImmutable);

            $local = $takenAt->setTimezone($tz);
            $year  = (int) $local->format('Y');
            $day   = $local->format('Y-m-d');

            $byYearDay[$year] ??= [];
            $byYearDay[$year][$day] ??= [];
            $byYearDay[$year][$day][] = $media;
        }

        /** @var array<int, array<string, list<Media>>> $eligibleByYear */
        $eligibleByYear = [];

        foreach ($byYearDay as $year => $days) {
            $eligibleDays = $this->filterGroupsByMinItems($days, $this->minItemsPerDay);

            if ($eligibleDays !== []) {
                $eligibleByYear[$year] = $eligibleDays;
            }
        }

        if ($eligibleByYear === []) {
            return [];
        }

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];

        foreach ($eligibleByYear as $year => $eligibleDays) {
            $bestDay   = null;
            $bestCount = 0;

            foreach ($eligibleDays as $day => $list) {
                $count = count($list);
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestDay   = $day;
                }
            }

            if ($bestDay === null) {
                continue;
            }

            foreach ($eligibleDays[$bestDay] as $media) {
                $picked[] = $media;
            }

            $years[$year] = true;
        }

        if (count($years) < $this->minYears || count($picked) < $this->minItemsTotal) {
            return [];
        }

        usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $centroid = MediaMath::centroid($picked);
        $time     = MediaMath::timeRange($picked);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: $this->buildParams($years, $time),
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $media): int => $media->getId(), $picked)
            ),
        ];
    }

    abstract public function name(): string;

    /**
     * @param array<int,bool>        $years
     * @param array{from:int,to:int} $timeRange
     *
     * @return array<string,mixed>
     */
    protected function buildParams(array $years, array $timeRange): array
    {
        return [
            'years'      => array_values(array_keys($years)),
            'time_range' => $timeRange,
        ];
    }

    protected function matchesMedia(Media $media): bool
    {
        return $this->pathContainsKeyword($media->getPath());
    }

    private function pathContainsKeyword(string $path): bool
    {
        $lower = strtolower($path);

        return array_any($this->keywords, fn ($keyword): bool => str_contains($lower, $keyword));
    }
}
