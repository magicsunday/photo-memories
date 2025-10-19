<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ContextualClusterBridgeTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function assert;
use function count;

/**
 * Builds one macro cluster per year if enough items exist.
 */
final readonly class YearInReviewClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use ContextualClusterBridgeTrait;
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use ProgressAwareClusterTrait;

    private LocationHelper $locationHelper;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        LocationHelper $locationHelper,
        private int $minItemsPerYear = 150,
        private int $minDistinctMonths = 5,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->minItemsPerYear < 1) {
            throw new InvalidArgumentException('minItemsPerYear must be >= 1.');
        }

        if ($this->minDistinctMonths < 1 || $this->minDistinctMonths > 12) {
            throw new InvalidArgumentException('minDistinctMonths must be within 1..12.');
        }

        $this->locationHelper    = $locationHelper;
        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'year_in_review';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<int, list<Media>> $byYear */
        $byYear = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $y = (int) $t->format('Y');
            $byYear[$y] ??= [];
            $byYear[$y][] = $m;
        }

        $eligibleYears = $this->filterGroupsByMinItems($byYear, $this->minItemsPerYear);

        $eligibleYears = $this->filterGroups(
            $eligibleYears,
            function (array $list): bool {
                /** @var array<int,bool> $months */
                $months = [];
                foreach ($list as $m) {
                    $months[(int) $m->getTakenAt()->format('n')] = true;
                }

                $count = count($months);

                return $count >= $this->minDistinctMonths;
            }
        );

        if ($eligibleYears === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleYears as $year => $list) {
            $centroid = $this->computeCentroid($list);
            $time     = $this->computeTimeRange($list);

            $params = [
                'year'       => $year,
                'time_range' => $time,
            ];

            $tags = $this->collectDominantTags($list);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $params = $this->appendLocationMetadata($list, $params);

            $qualityParams = $this->qualityAggregator->buildParams($list);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $peopleParams = $this->buildPeopleParams($list);
            $params       = [...$params, ...$peopleParams];

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($list)
            );
        }

        return $out;
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, Context $ctx, callable $update): array
    {
        return $this->runWithDefaultProgress(
            $items,
            $ctx,
            $update,
            fn (array $payload, Context $context): array => $this->draft($payload, $context)
        );
    }

}
