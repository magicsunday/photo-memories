<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best multi-day winter snow vacation per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 63])]
final class SnowVacationOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    /** @var list<string> */
    private const KEYWORDS = ['schnee', 'snow', 'ski', 'langlauf', 'skitour', 'snowboard', 'piste', 'gondel', 'lift', 'alpen', 'hütte', 'huette'];

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 4,
        private readonly int $minNights = 3,
        private readonly int $maxNights = 14,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 30
    ) {
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
        }
    }

    public function name(): string
    {
        return 'snow_vacation_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        $byYearDay = $this->buildYearDayIndex(
            $items,
            $tz,
            function (Media $media, DateTimeImmutable $local): bool {
                if (!$this->mediaPathContains($media, self::KEYWORDS)) {
                    return false;
                }

                $month = (int) $local->format('n');
                return $month === 12 || $month <= 2;
            }
        );

        $membersAllYears = [];
        $yearsPicked     = [];

        foreach ($byYearDay as $year => $daysMap) {
            $runs = $this->buildConsecutiveRuns($daysMap);

            $candidates = [];
            foreach ($runs as $run) {
                $nights = \count($run['days']) - 1;
                if ($nights < $this->minNights || $nights > $this->maxNights) {
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

            \usort($candidates, static function (array $a, array $b): int {
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
}
