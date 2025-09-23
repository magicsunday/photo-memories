<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best weekend getaway (1..3 nights) per year and aggregates them into one over-years memory.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 61])]
final class WeekendGetawaysOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minNights = 1,
        private readonly int $maxNights = 3,
        private readonly int $minItemsPerDay = 4,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 24
    ) {
        if ($this->minNights < 1) {
            throw new \InvalidArgumentException('minNights must be >= 1.');
        }
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
        }
    }

    public function name(): string
    {
        return 'weekend_getaways_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        $byYearDay = $this->buildYearDayIndex($items, $tz);

        $membersAllYears = [];
        $yearsPicked     = [];

        foreach ($byYearDay as $year => $daysMap) {
            $runs = $this->buildConsecutiveRuns($daysMap);

            $candidates = [];

            foreach ($runs as $run) {
                $nDays = \count($run['days']);
                if ($nDays < 2) {
                    continue;
                }
                $nights = $nDays - 1;
                if ($nights < $this->minNights || $nights > $this->maxNights) {
                    continue;
                }
                if (!$this->containsWeekendDay($run['days'])) {
                    continue;
                }

                $ok = true;
                foreach ($run['days'] as $day) {
                    if (\count($daysMap[$day]) < $this->minItemsPerDay) {
                        $ok = false;
                        break;
                    }
                }

                if ($ok) {
                    $candidates[] = $run;
                }
            }

            if ($candidates === []) {
                continue;
            }

            \usort($candidates, function (array $a, array $b): int {
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
            });

            $best = $candidates[0];
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
            $this->name()
        );
    }

    /**
     * @param list<string> $days
     */
    private function containsWeekendDay(array $days): bool
    {
        foreach ($days as $d) {
            $ts = \strtotime($d . ' 12:00:00');
            if ($ts === false) {
                continue;
            }
            $dow = (int) \gmdate('N', $ts); // 1..7
            if ($dow === 6 || $dow === 7) {
                return true;
            }
        }
        return false;
    }
}
