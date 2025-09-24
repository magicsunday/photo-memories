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
    private ?string $currentGroupKey = null;

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

        $this->beforeGrouping();

        $grouped = $this->buildGroupedDaysMap($items);
        if ($grouped === []) {
            return [];
        }

        $drafts = [];

        foreach ($grouped as $groupKey => $daysMap) {
            foreach ($daysMap as $day => $list) {
                if (!$this->isDayEligible($day, $list, $groupKey)) {
                    unset($daysMap[$day]);
                }
            }

            if ($daysMap === []) {
                continue;
            }

            $this->currentGroupKey = $groupKey;

            $runs = $this->buildRuns($daysMap, $groupKey);

            foreach ($runs as $run) {
                $nights = \max(0, \count($run['days']) - 1);
                if ($nights < $this->minNights || $nights > $this->maxNights) {
                    continue;
                }

                $members = $run['items'];
                if (\count($members) < $this->minItemsTotal) {
                    continue;
                }

                if (!$this->isRunValid($run, $daysMap, $nights, $members, $groupKey)) {
                    continue;
                }

                $drafts[] = $this->buildClusterDraft(
                    $this->name(),
                    $members,
                    $this->runParams($run, $daysMap, $nights, $members, $groupKey)
                );
            }
        }

        $this->currentGroupKey = null;

        return $drafts;
    }

    /**
     * @param list<Media> $items
     * @return array<string, array<string, list<Media>>>
     */
    private function buildGroupedDaysMap(array $items): array
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

            $groupKey = $this->groupKey($media, $local);
            if ($groupKey === null) {
                continue;
            }

            $day = $local->format('Y-m-d');
            $map[$groupKey] ??= [];
            $map[$groupKey][$day] ??= [];
            $map[$groupKey][$day][] = $media;
        }

        return $map;
    }

    protected function beforeGrouping(): void
    {
        // default no-op
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
    protected function isDayEligible(string $day, array $items, string $groupKey): bool
    {
        return \count($items) >= $this->minItemsPerDay;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param array<string, list<Media>> $daysMap
     * @param list<Media> $members
     */
    protected function isRunValid(array $run, array $daysMap, int $nights, array $members, string $groupKey): bool
    {
        return true;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param array<string, list<Media>> $daysMap
     * @param list<Media> $members
     * @return array<string, mixed>
     */
    protected function runParams(array $run, array $daysMap, int $nights, array $members, string $groupKey): array
    {
        return ['nights' => $nights];
    }

    protected function buildRuns(array $daysMap, string $groupKey): array
    {
        return $this->buildConsecutiveRuns($daysMap);
    }

    protected function groupKey(Media $media, DateTimeImmutable $local): ?string
    {
        return '__default__';
    }

    protected function currentGroupKey(): ?string
    {
        return $this->currentGroupKey;
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

}
