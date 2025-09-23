<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks best zoo/aquarium day per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 62])]
final class ZooAquariumOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    /** @var list<string> */
    private const KEYWORDS = ['zoo', 'tierpark', 'wildpark', 'safari park', 'aquarium', 'sealife', 'sea life', 'zoopark'];

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 5,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 18
    ) {
    }

    public function name(): string
    {
        return 'zoo_aquarium_over_years';
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

        $best = $this->pickBestDayPerYear($byYearDay, $this->minItemsPerDay);

        return $this->buildOverYearsDrafts(
            $best['members'],
            $best['years'],
            $this->minYears,
            $this->minItemsTotal,
            $this->name()
        );
    }
}
