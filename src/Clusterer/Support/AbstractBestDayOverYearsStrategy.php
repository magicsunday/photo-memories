<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;

/**
 * Base class for strategies that pick the best day per year and aggregate the result across years.
 */
abstract class AbstractBestDayOverYearsStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone,
        private readonly int $minItemsPerDay,
        private readonly int $minYears,
        private readonly int $minItemsTotal
    ) {
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
            $this->timezone(),
            fn (Media $media, DateTimeImmutable $local): bool => $this->shouldConsider($media, $local)
        );

        $best = $this->pickBestDayPerYear($byYearDay, $this->minItemsPerDay);

        return $this->buildOverYearsDrafts(
            $best['members'],
            $best['years'],
            $this->minYears,
            $this->minItemsTotal,
            $this->name(),
            $this->additionalDraftParams($best)
        );
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return true;
    }

    /**
     * @param array{members:list<Media>, years:array<int,bool>} $best
     * @return array<string,mixed>
     */
    protected function additionalDraftParams(array $best): array
    {
        return [];
    }

    protected function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    protected function minItemsPerDay(): int
    {
        return $this->minItemsPerDay;
    }

    protected function minYears(): int
    {
        return $this->minYears;
    }

    protected function minItemsTotal(): int
    {
        return $this->minItemsTotal;
    }

    /**
     * @param list<string> $keywords
     */
    protected function mediaMatchesKeywords(Media $media, array $keywords): bool
    {
        return $this->mediaPathContains($media, $keywords);
    }
}
