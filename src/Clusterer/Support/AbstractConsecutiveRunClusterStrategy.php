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
 * Base implementation for multi-day run strategies operating on consecutive days.
 */
abstract class AbstractConsecutiveRunClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone,
        private readonly int $minItemsPerDay,
        private readonly int $minItemsTotal,
        private readonly int $minNights,
        private readonly int $maxNights = \PHP_INT_MAX
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
        if (!$this->isEnabled()) {
            return [];
        }

        $daysMap = $this->buildDaysMap($items);

        foreach ($daysMap as $day => $list) {
            if (!$this->isDayEligible($day, $list)) {
                unset($daysMap[$day]);
            }
        }

        if ($daysMap === []) {
            return [];
        }

        $drafts = [];
        $runs = $this->buildConsecutiveRuns($daysMap);

        foreach ($runs as $run) {
            $nights = \max(0, \count($run['days']) - 1);
            if ($nights < $this->minNights || $nights > $this->maxNights) {
                continue;
            }

            $members = $run['items'];
            if (\count($members) < $this->minItemsTotal) {
                continue;
            }

            if (!$this->isRunValid($run, $daysMap, $nights, $members)) {
                continue;
            }

            $drafts[] = $this->buildClusterDraft(
                $this->name(),
                $members,
                $this->runParams($run, $daysMap, $nights, $members)
            );
        }

        return $drafts;
    }

    /**
     * @param list<Media> $items
     * @return array<string, list<Media>>
     */
    private function buildDaysMap(array $items): array
    {
        $map = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $takenAt->setTimezone($this->timezone);
            if (!$this->shouldConsider($media, $local)) {
                continue;
            }

            $day = $local->format('Y-m-d');
            $map[$day] ??= [];
            $map[$day][] = $media;
        }

        return $map;
    }

    protected function isEnabled(): bool
    {
        return true;
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
     * @param list<Media> $members
     */
    protected function isRunValid(array $run, array $daysMap, int $nights, array $members): bool
    {
        return true;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param array<string, list<Media>> $daysMap
     * @param list<Media> $members
     * @return array<string, mixed>
     */
    protected function runParams(array $run, array $daysMap, int $nights, array $members): array
    {
        return ['nights' => $nights];
    }

    protected function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    protected function minItemsPerDay(): int
    {
        return $this->minItemsPerDay;
    }

    protected function minItemsTotal(): int
    {
        return $this->minItemsTotal;
    }

    protected function minNights(): int
    {
        return $this->minNights;
    }

    protected function maxNights(): int
    {
        return $this->maxNights;
    }

    /**
     * @param list<string> $keywords
     */
    protected function mediaMatchesKeywords(Media $media, array $keywords): bool
    {
        return $this->mediaPathContains($media, $keywords);
    }
}
