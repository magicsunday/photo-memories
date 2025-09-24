<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;

/**
 * Base class for strategies that pick the best multi-day run per year and aggregate the result across years.
 */
abstract class AbstractConsecutiveRunOverYearsStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone,
        private readonly int $minNights,
        private readonly int $maxNights,
        private readonly int $minItemsPerDay,
        private readonly int $minYears,
        private readonly int $minItemsTotal
    ) {
        if ($maxNights < $minNights) {
            throw new InvalidArgumentException('maxNights must be >= minNights.');
        }

        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    final public function cluster(array $items): array
    {
        $byYearDay = $this->buildYearDayIndex(
            $items,
            $this->timezone,
            fn (Media $media, DateTimeImmutable $local): bool => $this->shouldConsider($media, $local)
        );

        $membersAllYears = [];
        $yearsPicked = [];

        foreach ($byYearDay as $year => $daysMap) {
            $best = $this->findBestRun($daysMap);
            if ($best === null) {
                continue;
            }

            foreach ($best['items'] as $media) {
                $membersAllYears[] = $media;
            }
            $yearsPicked[$year] = true;
        }

        return $this->buildOverYearsDrafts(
            $membersAllYears,
            $yearsPicked,
            $this->minYears,
            $this->minItemsTotal,
            $this->name(),
            $this->additionalDraftParams()
        );
    }

    /**
     * @param array<string, list<Media>> $daysMap
     * @return array{days:list<string>, items:list<Media>}|null
     */
    private function findBestRun(array $daysMap): ?array
    {
        foreach ($daysMap as $day => $list) {
            if (!$this->isDayEligible($day, $list)) {
                unset($daysMap[$day]);
            }
        }

        if ($daysMap === []) {
            return null;
        }

        $runs = $this->buildConsecutiveRuns($daysMap);

        $candidates = [];
        foreach ($runs as $run) {
            $nights = \count($run['days']) - 1;
            if ($nights < $this->minNights || $nights > $this->maxNights) {
                continue;
            }
            if (!$this->isRunValid($run, $daysMap)) {
                continue;
            }
            $candidates[] = $run;
        }

        if ($candidates === []) {
            return null;
        }

        \usort($candidates, self::runComparator());

        return $candidates[0];
    }

    /**
     * @return callable(array{days:list<string>, items:list<Media>}, array{days:list<string>, items:list<Media>}):int
     */
    private static function runComparator(): callable
    {
        return static function (array $a, array $b): int {
            $na = \count($a['items']);
            $nb = \count($b['items']);
            if ($na !== $nb) {
                return $na < $nb ? 1 : -1;
            }

            $sa = \count($a['days']);
            $sb = \count($b['days']);
            if ($sa !== $sb) {
                return $sa < $sb ? 1 : -1;
            }

            return \strcmp($a['days'][0], $b['days'][0]);
        };
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return true;
    }

    /**
     * @param list<Media> $items
     */
    protected function isDayEligible(string $day, array $items): bool
    {
        return \count($items) >= $this->minItemsPerDay;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param array<string, list<Media>> $daysMap
     */
    protected function isRunValid(array $run, array $daysMap): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    protected function additionalDraftParams(): array
    {
        return [];
    }

    /**
     * @param list<string> $days
     */
    protected function containsWeekendDay(array $days): bool
    {
        foreach ($days as $d) {
            $ts = \strtotime($d . ' 12:00:00');
            if ($ts === false) {
                continue;
            }
            $dow = (int) \gmdate('N', $ts);
            if ($dow === 6 || $dow === 7) {
                return true;
            }
        }

        return false;
    }
}
