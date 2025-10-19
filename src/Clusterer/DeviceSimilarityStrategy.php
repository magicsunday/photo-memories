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
use MagicSunday\Memories\Clusterer\Support\ClusterDeviceMetadataAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function assert;
use function count;

/**
 * Class DeviceSimilarityStrategy.
 */
final readonly class DeviceSimilarityStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use ContextualClusterBridgeTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use MediaFilterTrait;
    use ProgressAwareClusterTrait;

    private ClusterQualityAggregator $qualityAggregator;
    private ClusterDeviceMetadataAggregator $deviceAggregator;

    public function __construct(
        private LocationHelper $locationHelper,
        private int $minItemsPerGroup = 5,
        ?ClusterQualityAggregator $qualityAggregator = null,
        ?ClusterDeviceMetadataAggregator $deviceAggregator = null,
    ) {
        if ($this->minItemsPerGroup < 1) {
            throw new InvalidArgumentException('minItemsPerGroup must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
        $this->deviceAggregator  = $deviceAggregator ?? new ClusterDeviceMetadataAggregator();
    }

    public function name(): string
    {
        return 'device_similarity';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $withTimestamp */
        $withTimestamp = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $groups */
        $groups = [];

        /** @var array<string, string> $devices */
        $devices = [];

        $ingest = function (Media $m, string $date) use (&$groups, &$devices): void {
            $descriptor = $this->deviceAggregator->describe($m);
            $locKey     = $this->locationHelper->localityKeyForMedia($m) ?? 'noloc';

            $key = $descriptor['key'] . '|' . $date . '|' . $locKey;
            $groups[$key] ??= [];
            $groups[$key][] = $m;
            $devices[$key]  = $descriptor['label'];
        };

        foreach ($withTimestamp as $m) {
            $takenAt = $m->getTakenAt();
            assert($takenAt instanceof DateTimeImmutable);
            $ingest($m, $takenAt->format('Y-m-d'));
        }

        foreach ($items as $m) {
            if ($m->getTakenAt() instanceof DateTimeImmutable) {
                continue;
            }

            // Group timestamp-less media separately so they can still surface.
            $ingest($m, 'ohne-datum');
        }

        /** @var array<string, list<Media>> $eligibleGroups */
        $eligibleGroups = $this->filterGroupsByMinItems($groups, $this->minItemsPerGroup);

        $drafts = [];
        foreach ($eligibleGroups as $key => $group) {
            $summary = $this->deviceAggregator->summarize($group);

            $params = [
                'time_range' => $this->computeTimeRange($group),
                'device'     => $summary['device_primary_label'] ?? ($devices[$key] ?? 'Unbekanntes Gerät'),
            ];

            if ($summary['lensModel'] !== null) {
                $params['lensModel'] = $summary['lensModel'];
            }

            if ($summary['contentKind'] !== null) {
                $params['contentKind'] = $summary['contentKind'];
            }

            if ($summary['device_make'] !== null) {
                $params['device_make'] = $summary['device_make'];
            }

            if ($summary['device_model'] !== null) {
                $params['device_model'] = $summary['device_model'];
            }

            if ($summary['device_owner'] !== null) {
                $params['device_owner'] = $summary['device_owner'];
            }

            if ($summary['device_serial'] !== null) {
                $params['device_serial'] = $summary['device_serial'];
            }

            if ($summary['device_primary_share'] !== null) {
                $params['device_primary_share'] = $summary['device_primary_share'];
            }

            if ($summary['device_variants'] > 0) {
                $params['device_variants'] = $summary['device_variants'];
            }

            $peopleParams = $this->buildPeopleParams($group);
            $params       = [...$params, ...$peopleParams];

            $tagMetadata = $this->collectDominantTags($group);
            if ($tagMetadata !== []) {
                $params = [...$params, ...$tagMetadata];
            }

            $qualityParams = $this->qualityAggregator->buildParams($group);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $params = $this->appendLocationMetadata($group, $params);

            $drafts[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: $this->computeCentroid($group),
                members: $this->toMemberIds($group)
            );
        }

        return $drafts;
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
