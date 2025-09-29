<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function array_merge;
use function count;
use function implode;
use function ksort;
use function method_exists;
use function sort;

/**
 * Clusters items by stable co-occurrence of persons within a time window.
 * Requires Media to expose person tags via getPersonIds() -> list<int>.
 */
final readonly class PersonCohortClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private int $minPersons = 2,
        private int $minItemsTotal = 5,
        private int $windowDays = 14,
    ) {
        if ($this->minPersons < 1) {
            throw new InvalidArgumentException('minPersons must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }

        if ($this->windowDays < 0) {
            throw new InvalidArgumentException('windowDays must be >= 0.');
        }
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
        /** @var array<string, array<string, list<Media>>> $buckets sig => day => items */
        $buckets     = [];
        $withPersons = $this->filterTimestampedItemsBy(
            $items,
            static fn (Media $m): bool => method_exists($m, 'getPersonIds')
        );

        if ($withPersons === []) {
            return [];
        }

        // Phase 1: bucket by persons signature per day
        foreach ($withPersons as $m) {
            /** @var list<int> $persons */
            $persons = (array) $m->getPersonIds();
            if (count($persons) < $this->minPersons) {
                continue;
            }

            sort($persons);
            $sig = 'p:' . implode('-', array_map(static fn (int $id): string => (string) $id, $persons));
            $day = $m->getTakenAt()?->format('Y-m-d') ?? '1970-01-01';

            $buckets[$sig] ??= [];
            $buckets[$sig][$day] ??= [];
            $buckets[$sig][$day][] = $m;
        }

        /** @var array<string, array<string, list<Media>>> $eligibleBuckets */
        $eligibleBuckets = $this->filterGroups(
            $buckets,
            function (array $byDay): bool {
                $total = 0;
                foreach ($byDay as $list) {
                    $total += count($list);
                    if ($total >= $this->minItemsTotal) {
                        return true;
                    }
                }

                return false;
            }
        );

        if ($eligibleBuckets === []) {
            return [];
        }

        $clusters = [];

        // Phase 2: merge consecutive days within window for each signature
        foreach ($eligibleBuckets as $byDay) {
            ksort($byDay);

            $current = [];
            $lastDay = null;

            foreach ($byDay as $day => $list) {
                if ($lastDay === null) {
                    $current = $list;
                    $lastDay = $day;
                    continue;
                }

                $gapDays = (new DateTimeImmutable($day))->diff(new DateTimeImmutable($lastDay))->days;
                $gapDays = $gapDays === false || $gapDays === null ? 0 : (int) $gapDays;

                if ($gapDays <= $this->windowDays) {
                    /** @var list<Media> $current */
                    $current = array_merge($current, $list);
                    $lastDay = $day;
                    continue;
                }

                if (count($current) >= $this->minItemsTotal) {
                    $clusters[] = $this->makeDraft($current);
                }

                $current = $list;
                $lastDay = $day;
            }

            if (count($current) >= $this->minItemsTotal) {
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
            members: array_map(static fn (Media $m): int => $m->getId(), $members)
        );
    }
}
