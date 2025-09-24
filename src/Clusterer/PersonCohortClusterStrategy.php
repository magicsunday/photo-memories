<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractConsecutiveRunClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters items by stable co-occurrence of persons within a time window.
 * Requires Media to expose person tags via getPersonIds() -> list<int>.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 80])]
final class PersonCohortClusterStrategy extends AbstractConsecutiveRunClusterStrategy
{
    /** @var array<string, list<int>> */
    private array $groupPersons = [];

    public function __construct(
        private readonly int $minPersons = 2,
        private readonly int $minItems = 5,
        private readonly int $windowDays = 14,
        string $timezone = 'UTC'
    ) {
        parent::__construct($timezone, 1, $minItems, 0);
    }

    public function name(): string
    {
        return 'people_cohort';
    }

    protected function beforeGrouping(): void
    {
        $this->groupPersons = [];
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        if (!\method_exists($media, 'getPersonIds')) {
            return false;
        }

        /** @var list<int> $persons */
        $persons = (array) $media->getPersonIds();

        return \count($persons) >= $this->minPersons;
    }

    protected function groupKey(Media $media, DateTimeImmutable $local): ?string
    {
        /** @var list<int> $persons */
        $persons = (array) $media->getPersonIds();
        if (\count($persons) < $this->minPersons) {
            return null;
        }

        \sort($persons);
        $key = 'p:' . \implode('-', \array_map(static fn (int $id): string => (string) $id, $persons));
        $this->groupPersons[$key] = $persons;

        return $key;
    }

    /**
     * @param array<string, list<Media>> $daysMap
     * @return list<array{days:list<string>, items:list<Media>}>
     */
    protected function buildRuns(array $daysMap, string $groupKey): array
    {
        if ($this->windowDays <= 0) {
            return parent::buildRuns($daysMap, $groupKey);
        }

        $days = \array_keys($daysMap);
        \sort($days, \SORT_STRING);

        $runs = [];
        $runDays = [];
        $runItems = [];
        $lastDay = null;

        foreach ($days as $day) {
            if ($lastDay !== null) {
                $gap = $this->gapDays($lastDay, $day);
                if ($gap > $this->windowDays) {
                    if ($runDays !== []) {
                        $runs[] = ['days' => $runDays, 'items' => $runItems];
                        $runDays = [];
                        $runItems = [];
                    }
                }
            }

            $runDays[] = $day;
            foreach ($daysMap[$day] as $media) {
                $runItems[] = $media;
            }
            $lastDay = $day;
        }

        if ($runDays !== []) {
            $runs[] = ['days' => $runDays, 'items' => $runItems];
        }

        return $runs;
    }

    protected function runParams(array $run, array $daysMap, int $nights, array $members, string $groupKey): array
    {
        $params = [];

        if (isset($this->groupPersons[$groupKey])) {
            $params['person_ids'] = $this->groupPersons[$groupKey];
        }

        return $params;
    }

    private function gapDays(string $previousDay, string $currentDay): int
    {
        $previous = new DateTimeImmutable($previousDay);
        $current = new DateTimeImmutable($currentDay);
        $diff = $current->diff($previous);

        return (int) ($diff->days ?? 0);
    }
}
