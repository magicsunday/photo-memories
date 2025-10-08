<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\PersonSignatureHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function ksort;
use function mb_strtolower;
use function sort;
use function strcasecmp;
use function trim;
use function usort;

/**
 * Clusters items by stable co-occurrence of persons within a time window.
 */
final readonly class PersonCohortClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;

    private PersonSignatureHelper $personSignatureHelper;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        private LocationHelper $locationHelper,
        private int $minPersons = 2,
        private int $minItemsTotal = 5,
        private int $windowDays = 14,
        ?PersonSignatureHelper $personSignatureHelper = null,
        ?ClusterQualityAggregator $qualityAggregator = null,
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

        $this->personSignatureHelper = $personSignatureHelper ?? new PersonSignatureHelper();
        $this->qualityAggregator     = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'people_cohort';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     *
     * @throws DateMalformedStringException
     */
    public function cluster(array $items): array
    {
        /** @var array<string, array<string, list<Media>>> $buckets sig => day => items */
        $buckets    = [];
        $candidates = $this->filterTimestampedItems($items);

        if ($candidates === []) {
            return [];
        }

        // Phase 1: bucket by persons signature per day
        foreach ($candidates as $m) {
            $persons = $this->personSignatureHelper->personIds($m);
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
        $centroid = $this->computeCentroid($members);

        $personIdSet = [];
        $labelSet    = [];

        foreach ($members as $media) {
            foreach ($this->personSignatureHelper->personIds($media) as $personId) {
                $personIdSet[$personId] = true;
            }

            $labels = $media->getPersons();
            if (!is_array($labels)) {
                continue;
            }

            foreach ($labels as $label) {
                if (!is_string($label)) {
                    continue;
                }

                $normalized = trim($label);
                if ($normalized === '') {
                    continue;
                }

                $key = mb_strtolower($normalized);
                $labelSet[$key] ??= $normalized;
            }
        }

        $persons = array_map(static fn (int|string $value): int => (int) $value, array_keys($personIdSet));
        sort($persons);

        $personLabels = array_values($labelSet);
        usort($personLabels, static fn (string $a, string $b): int => strcasecmp($a, $b));

        $params = [
            'time_range' => $this->computeTimeRange($members),
            'quality_profile' => 'group_portrait',
        ];

        if ($persons !== []) {
            $params['persons'] = $persons;
        }

        if ($personLabels !== []) {
            $params['person_labels'] = $personLabels;
        }

        $draft = new ClusterDraft(
            algorithm: $this->name(),
            params: $params,
            centroid: ['lat' => $centroid['lat'], 'lon' => $centroid['lon']],
            members: $this->toMemberIds($members)
        );

        $tagMetadata = $this->collectDominantTags($members);
        foreach ($tagMetadata as $key => $value) {
            $draft->setParam($key, $value);
        }

        $this->applyLocationMetadata($draft, $members);

        $qualityParams = $this->qualityAggregator->buildParams($members);
        foreach ($qualityParams as $qualityKey => $qualityValue) {
            if ($qualityValue !== null) {
                $draft->setParam($qualityKey, $qualityValue);
            }
        }

        return $draft;
    }
}
