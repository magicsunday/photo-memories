<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters items by stable co-occurrence of persons within a time window.
 * Requires Media to expose person tags via getPersonIds() -> list<int>.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 80])]
final class PersonCohortClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $minPersons = 2,
        private readonly int $minItems   = 5,
        private readonly int $windowDays = 14
    ) {
    }

    public function name(): string
    {
        return 'people_cohort';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // If there is no person method, exit early
        $hasMethod = \count(\array_filter(
                $items,
                static fn (Media $m): bool => \method_exists($m, 'getPersonIds')
            )) > 0;

        if (!$hasMethod) {
            return [];
        }

        /** @var array<string, array<string, list<Media>>> $buckets sig => day => items */
        $buckets = [];
        $withTime = \array_values(\array_filter(
            $items,
            static fn (Media $m): bool => $m->getTakenAt() !== null
        ));

        // Phase 1: bucket by persons signature per day
        foreach ($withTime as $m) {
            /** @var list<int> $persons */
            $persons = \method_exists($m, 'getPersonIds') ? (array) $m->getPersonIds() : [];
            if (\count($persons) < $this->minPersons) {
                continue;
            }

            \sort($persons);
            $sig = 'p:' . \implode('-', \array_map(static fn (int $id): string => (string) $id, $persons));
            $day = $m->getTakenAt()?->format('Y-m-d') ?? '1970-01-01';

            $buckets[$sig] ??= [];
            $buckets[$sig][$day] ??= [];
            $buckets[$sig][$day][] = $m;
        }

        if (\count($buckets) === 0) {
            return [];
        }

        $clusters = [];

        // Phase 2: merge consecutive days within window for each signature
        foreach ($buckets as $sig => $byDay) {
            \ksort($byDay);

            $current = [];
            $lastDay = null;

            foreach ($byDay as $day => $list) {
                if ($lastDay === null) {
                    $current = $list;
                    $lastDay = $day;
                    continue;
                }

                $gapDays = (new \DateTimeImmutable($day))->diff(new \DateTimeImmutable($lastDay))->days;
                $gapDays = $gapDays === false || $gapDays === null ? 0 : (int) $gapDays;

                if ($gapDays <= $this->windowDays) {
                    /** @var list<Media> $current */
                    $current = \array_merge($current, $list);
                    $lastDay = $day;
                    continue;
                }

                if (\count($current) >= $this->minItems) {
                    $clusters[] = $this->makeDraft($current);
                }

                $current = $list;
                $lastDay = $day;
            }

            if (\count($current) >= $this->minItems) {
                $clusters[] = $this->makeDraft($current);
            }
        }

        return $clusters;
    }

    /**
     * @param list<Media> $members
     */
    private function makeDraft(array $members): ClusterDraft
    {
        $centroid = MediaMath::centroid($members);

        return new ClusterDraft(
            algorithm: $this->name(),
            params: [
                'time_range' => MediaMath::timeRange($members),
            ],
            centroid: ['lat' => $centroid['lat'], 'lon' => $centroid['lon']],
            members: \array_map(static fn (Media $m): int => $m->getId(), $members)
        );
    }
}
