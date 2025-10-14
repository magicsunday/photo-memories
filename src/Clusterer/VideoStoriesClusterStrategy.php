<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterDeviceMetadataAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function assert;
use function count;
use function is_string;
use function str_starts_with;
use function usort;

/**
 * Collects videos into day-based stories (local time).
 */
final readonly class VideoStoriesClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use ProgressAwareClusterTrait;

    private ClusterQualityAggregator $qualityAggregator;
    private ClusterDeviceMetadataAggregator $deviceAggregator;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        // Minimum number of videos per local day to emit a story.
        private int $minItemsPerDay = 2,
        ?ClusterQualityAggregator $qualityAggregator = null,
        ?ClusterDeviceMetadataAggregator $deviceAggregator = null,
    ) {
        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
        $this->deviceAggregator  = $deviceAggregator ?? new ClusterDeviceMetadataAggregator();
    }

    public function name(): string
    {
        return 'video_stories';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        $videoItems = $this->filterTimestampedItemsBy(
            $items,
            static function (Media $m): bool {
                if ($m->isVideo() === true) {
                    return true;
                }

                if ($m->isVideo() === false && $m->getIndexedAt() === null) {
                    $mime = $m->getMime();

                    return is_string($mime) && str_starts_with($mime, 'video/');
                }

                return false;
            }
        );

        foreach ($videoItems as $m) {
            $local = $this->localTimeHelper->resolve($m);
            assert($local instanceof DateTimeImmutable);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleDays */
        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleDays as $members) {
            usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

            $centroid = $this->computeCentroid($members);
            $time     = $this->computeTimeRange($members);

            $videoCount           = count($members);
            $videoDurationTotal   = 0.0;
            $videoSlowMoCount     = 0;
            $videoStabilizedCount = 0;

            foreach ($members as $member) {
                $videoDuration = $member->getVideoDurationS();
                if ($videoDuration !== null) {
                    $videoDurationTotal += $videoDuration;
                }

                $isSlowMo = $member->isSlowMo();
                if ($isSlowMo === true) {
                    ++$videoSlowMoCount;
                }

                $hasStabilization = $member->getVideoHasStabilization();
                if ($hasStabilization === true) {
                    ++$videoStabilizedCount;
                }
            }

            $params = [
                'time_range' => $time,
            ];

            if ($videoCount > 0) {
                $params['video_count'] = $videoCount;
            }

            if ($videoDurationTotal > 0.0) {
                $params['video_duration_total_s'] = $videoDurationTotal;
            }

            if ($videoSlowMoCount > 0) {
                $params['video_slow_mo_count'] = $videoSlowMoCount;
            }

            if ($videoStabilizedCount > 0) {
                $params['video_stabilized_count'] = $videoStabilizedCount;
            }

            $tags = $this->collectDominantTags($members);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $qualityParams = $this->qualityAggregator->buildParams($members);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $peopleParams = $this->buildPeopleParams($members);
            $params       = [...$params, ...$peopleParams];

            $params = $this->appendLocationMetadata($members, $params);

            $deviceSummary = $this->deviceAggregator->summarize($members);

            if ($deviceSummary['device_primary_label'] !== null) {
                $params['device_primary_label'] = $deviceSummary['device_primary_label'];
            }

            if ($deviceSummary['device_primary_share'] !== null) {
                $params['device_primary_share'] = $deviceSummary['device_primary_share'];
            }

            if ($deviceSummary['device_variants'] > 0) {
                $params['device_variants'] = $deviceSummary['device_variants'];
            }

            if ($deviceSummary['device_make'] !== null) {
                $params['device_make'] = $deviceSummary['device_make'];
            }

            if ($deviceSummary['device_model'] !== null) {
                $params['device_model'] = $deviceSummary['device_model'];
            }

            if ($deviceSummary['device_owner'] !== null) {
                $params['device_owner'] = $deviceSummary['device_owner'];
            }

            if ($deviceSummary['device_serial'] !== null) {
                $params['device_serial'] = $deviceSummary['device_serial'];
            }

            if ($deviceSummary['lensModel'] !== null) {
                $params['device_lens_model'] = $deviceSummary['lensModel'];
            }

            if ($deviceSummary['contentKind'] !== null) {
                $params['device_content_kind'] = $deviceSummary['contentKind'];
            }

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($members)
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
    public function clusterWithProgress(array $items, callable $update): array
    {
        return $this->runWithDefaultProgress($items, $update, fn (array $payload): array => $this->cluster($payload));
    }

}
