<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best multi-day camping run per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 63])]
final class CampingOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    /** @var list<string> */
    private const KEYWORDS = ['camping', 'zelt', 'zelten', 'wohnmobil', 'caravan', 'wohnwagen', 'campground', 'camp site', 'campsite', 'stellplatz'];

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 3,
        private readonly int $minNights = 2,
        private readonly int $maxNights = 14,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 24
    ) {
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
        }
    }

    public function name(): string
    {
        return 'camping_over_years';
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
            fn (Media $media, DateTimeImmutable $local): bool => $this->mediaPathContains($media, self::KEYWORDS)
        );

        $picked = [];
        $years  = [];

        foreach ($byYearDay as $year => $daysMap) {
            foreach ($daysMap as $day => $list) {
                if (\count($list) < $this->minItemsPerDay) {
                    unset($daysMap[$day]);
                }
            }

            if ($daysMap === []) {
                continue;
            }

            $runs = $this->buildConsecutiveRuns($daysMap);

            $candidates = [];
            foreach ($runs as $run) {
                $nights = \count($run['days']) - 1;
                if ($nights < $this->minNights || $nights > $this->maxNights) {
                    continue;
                }
                $candidates[] = $run;
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
                $picked[] = $media;
            }
            $years[$year] = true;
        }

        return $this->buildOverYearsDrafts(
            $picked,
            $years,
            $this->minYears,
            $this->minItemsTotal,
            $this->name()
        );
    }
}
