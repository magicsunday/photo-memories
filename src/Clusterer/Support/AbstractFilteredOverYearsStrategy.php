<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;

/**
 * Base for strategies that filter media per year and build a single over-years memory.
 */
abstract class AbstractFilteredOverYearsStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone,
        private readonly int $perYearMin,
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
            fn (Media $media, DateTimeImmutable $local): bool => $this->shouldInclude($media, $local)
        );

        if ($byYearDay === []) {
            return [];
        }

        $members = [];
        $years = [];

        foreach ($byYearDay as $year => $days) {
            $yearMembers = $this->flattenDayMembers($days);
            if ($yearMembers === []) {
                continue;
            }

            if (!$this->isYearEligible($year, $yearMembers)) {
                continue;
            }

            $normalized = $this->normalizeYearMembers($year, $yearMembers);
            if ($normalized === []) {
                continue;
            }

            foreach ($normalized as $media) {
                $members[] = $media;
            }

            $years[$year] = true;
        }

        return $this->buildOverYearsDrafts(
            $members,
            $years,
            $this->minYears,
            $this->minItemsTotal,
            $this->name(),
            $this->additionalDraftParams($members, $years)
        );
    }

    protected function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    protected function perYearMin(): int
    {
        return $this->perYearMin;
    }

    protected function minYears(): int
    {
        return $this->minYears;
    }

    protected function minItemsTotal(): int
    {
        return $this->minItemsTotal;
    }

    protected function isYearEligible(int $year, array $members): bool
    {
        return \count($members) >= $this->perYearMin;
    }

    /**
     * @param list<Media> $members
     *
     * @return list<Media>
     */
    protected function normalizeYearMembers(int $year, array $members): array
    {
        return $members;
    }

    /**
     * @param list<Media> $members
     * @param array<int, bool> $years
     *
     * @return array<string, mixed>
     */
    protected function additionalDraftParams(array $members, array $years): array
    {
        return [];
    }

    abstract protected function shouldInclude(Media $media, DateTimeImmutable $local): bool;
}
