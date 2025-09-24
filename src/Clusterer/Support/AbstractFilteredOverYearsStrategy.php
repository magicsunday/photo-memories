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
        /** @var array<int, list<Media>> $byYear */
        $byYear = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $takenAt->setTimezone($this->timezone);
            if (!$this->shouldInclude($media, $local)) {
                continue;
            }

            $year = (int) $local->format('Y');
            $byYear[$year] ??= [];
            $byYear[$year][] = $media;
        }

        if ($byYear === []) {
            return [];
        }

        $members = [];
        $years = [];

        foreach ($byYear as $year => $list) {
            if (!$this->isYearEligible($year, $list)) {
                continue;
            }

            $normalized = $this->normalizeYearMembers($year, $list);
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
