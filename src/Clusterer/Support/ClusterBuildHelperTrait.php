<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Small helper to build ClusterDraft fields from Media lists.
 */
trait ClusterBuildHelperTrait
{
    /**
     * @param list<Media> $members
     * @return array{lat: float, lon: float}
     */
    private function computeCentroid(array $members): array
    {
        return MediaMath::centroid($members);
    }

    /**
     * @param list<Media> $members
     * @return list<int>
     */
    private function toMemberIds(array $members): array
    {
        $out = [];
        foreach ($members as $m) {
            $out[] = $m->getId();
        }
        return $out;
    }

    /**
     * @param list<Media> $members
     * @return array{from:int,to:int}
     */
    private function computeTimeRange(array $members): array
    {
        $from = \PHP_INT_MAX;
        $to   = 0;

        foreach ($members as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts !== null) {
                if ($ts < $from) { $from = $ts; }
                if ($ts > $to)   { $to   = $ts; }
            }
        }

        if ($from === \PHP_INT_MAX) {
            $from = 0;
        }
        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param list<Media> $members
     */
    private function sortByTakenAt(array $members): array
    {
        \usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        return $members;
    }

    /**
     * @param list<Media> $members
     * @param array<string,mixed> $params
     */
    private function buildClusterDraft(string $algorithm, array $members, array $params): ClusterDraft
    {
        $members = $this->sortByTakenAt($members);

        $params['time_range'] ??= $this->computeTimeRange($members);

        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: $this->computeCentroid($members),
            members: $this->toMemberIds($members)
        );
    }

    /**
     * @param list<Media> $items
     * @param callable(Media, DateTimeImmutable):bool|null $filter
     * @return array<int, array<string, list<Media>>>
     */
    private function buildYearDayIndex(array $items, DateTimeZone $timezone, ?callable $filter = null): array
    {
        $byYearDay = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $takenAt->setTimezone($timezone);
            if ($filter !== null && !$filter($media, $local)) {
                continue;
            }

            $year = (int) $local->format('Y');
            $day  = $local->format('Y-m-d');

            $byYearDay[$year] ??= [];
            $byYearDay[$year][$day] ??= [];
            $byYearDay[$year][$day][] = $media;
        }

        return $byYearDay;
    }

    /**
     * @param array<int, array<string, list<Media>>> $byYearDay
     * @return array{members:list<Media>, years:array<int,bool>}
     */
    private function pickBestDayPerYear(array $byYearDay, int $minItemsPerDay): array
    {
        $picked = [];
        $years  = [];

        foreach ($byYearDay as $year => $days) {
            $bestDay   = null;
            $bestCount = 0;

            foreach ($days as $day => $list) {
                $count = \count($list);
                if ($count >= $minItemsPerDay && $count > $bestCount) {
                    $bestCount = $count;
                    $bestDay   = $day;
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

        return ['members' => $picked, 'years' => $years];
    }

    /**
     * @param list<Media> $members
     * @param array<int,bool> $years
     * @param array<string,mixed> $params
     * @return list<ClusterDraft>
     */
    private function buildOverYearsDrafts(
        array $members,
        array $years,
        int $minYears,
        int $minItemsTotal,
        string $algorithm,
        array $params = []
    ): array {
        if (\count($years) < $minYears || \count($members) < $minItemsTotal) {
            return [];
        }

        $params['years'] = \array_values(\array_keys($years));

        return [
            $this->buildClusterDraft($algorithm, $members, $params),
        ];
    }

    /**
     * @param array<string, list<Media>> $daysMap
     * @return list<array{days:list<string>, items:list<Media>}>
     */
    private function buildConsecutiveRuns(array $daysMap): array
    {
        $days = \array_keys($daysMap);
        \sort($days, \SORT_STRING);

        $runs = [];
        $runDays = [];
        $runItems = [];
        $prev = null;

        $flush = function () use (&$runs, &$runDays, &$runItems): void {
            if ($runDays === []) {
                return;
            }

            $runs[] = ['days' => $runDays, 'items' => $runItems];
            $runDays = [];
            $runItems = [];
        };

        foreach ($days as $day) {
            if ($prev !== null && !$this->isNextDay($prev, $day)) {
                $flush();
            }

            $runDays[] = $day;
            foreach ($daysMap[$day] as $media) {
                $runItems[] = $media;
            }
            $prev = $day;
        }

        $flush();

        return $runs;
    }

    /**
     * Collects distinct formatted date parts from the given members.
     *
     * @param list<Media> $members
     * @return array<string, bool>
     */
    protected function uniqueDateParts(array $members, string $format, ?DateTimeZone $timezone = null): array
    {
        $distinct = [];

        foreach ($members as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $timezone !== null ? $takenAt->setTimezone($timezone) : $takenAt;
            $distinct[$local->format($format)] = true;
        }

        return $distinct;
    }

    private function isNextDay(string $a, string $b): bool
    {
        $ta = \strtotime($a . ' 00:00:00');
        $tb = \strtotime($b . ' 00:00:00');
        if ($ta === false || $tb === false) {
            return false;
        }
        return ($tb - $ta) === 86400;
    }

    /**
     * @param list<string> $keywords
     */
    private function pathContainsKeyword(string $pathLower, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (\str_contains($pathLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $keywords
     */
    private function mediaPathContains(Media $media, array $keywords): bool
    {
        return $this->pathContainsKeyword(\strtolower($media->getPath()), $keywords);
    }
}
