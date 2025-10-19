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
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\ContextualClusterBridgeTrait;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\CalendarFeatureHelper;
use MagicSunday\Memories\Utility\LocationHelper;

use function assert;
use function count;
use function is_array;
use function is_string;
use function substr;

/**
 * Groups photos by local calendar day. Produces compact "Day Tour" clusters.
 */
final readonly class DayAlbumClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use ContextualClusterBridgeTrait;
    use ProgressAwareClusterTrait;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        private int $minItemsPerDay = 8,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'day_album';
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

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($timestamped as $m) {
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

        foreach ($eligibleDays as $key => $members) {
            $peopleParams   = $this->buildPeopleParams($members);
            $qualityParams  = $this->qualityAggregator->buildParams($members);
            $locationParams = $this->appendLocationMetadata($members, []);

            $hasPeople    = $this->hasPeoplePresence($peopleParams);
            $hasPoi       = $this->hasPoiMetadata($locationParams);
            $hasStaypoint = $this->hasStaypointDiversity($members);
            $hasQuality   = $this->hasQualitySignal($qualityParams);

            if ($hasPeople === false && $hasPoi === false && $hasStaypoint === false && $hasQuality === false) {
                continue;
            }

            $centroid = $this->computeCentroid($members);
            $time     = $this->computeTimeRange($members);

            $params = [
                'year'       => (int) substr($key, 0, 4),
                'time_range' => $time,
            ];

            $calendar = CalendarFeatureHelper::summarize($members);
            if ($calendar['isWeekend'] !== null) {
                $params['isWeekend'] = $calendar['isWeekend'];
            }

            if ($calendar['holidayId'] !== null) {
                $params['holidayId'] = $calendar['holidayId'];
            }

            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $tags = $this->collectDominantTags($members);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $params = [...$params, ...$peopleParams];

            if ($locationParams !== []) {
                $params = [...$params, ...$locationParams];
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
     * @param array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float} $peopleParams
     */
    private function hasPeoplePresence(array $peopleParams): bool
    {
        return $peopleParams['people_count'] > 0
            || $peopleParams['people'] > 0.0
            || $peopleParams['people_face_coverage'] > 0.0;
    }

    /**
     * @param array<string, mixed> $locationParams
     */
    private function hasPoiMetadata(array $locationParams): bool
    {
        if (isset($locationParams['poi_label']) && is_string($locationParams['poi_label']) && $locationParams['poi_label'] !== '') {
            return true;
        }

        if (isset($locationParams['poi_category_key']) && is_string($locationParams['poi_category_key']) && $locationParams['poi_category_key'] !== '') {
            return true;
        }

        if (isset($locationParams['poi_category_value']) && is_string($locationParams['poi_category_value']) && $locationParams['poi_category_value'] !== '') {
            return true;
        }

        if (isset($locationParams['poi_tags']) && is_array($locationParams['poi_tags']) && $locationParams['poi_tags'] !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param list<Media> $members
     */
    private function hasStaypointDiversity(array $members): bool
    {
        /** @var array<string, true> $keys */
        $keys = [];

        foreach ($members as $media) {
            $key = $this->locationHelper->localityKeyForMedia($media);
            if (!is_string($key) || $key === '') {
                continue;
            }

            $keys[$key] = true;

            if (count($keys) >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{quality_avg: float, aesthetics_score: float|null, quality_resolution: float|null, quality_sharpness: float|null, quality_exposure: float|null, quality_contrast: float|null, quality_noise: float|null, quality_blockiness: float|null, quality_video_keyframe: float|null, quality_video_bonus: float|null, quality_video_penalty: float|null, quality_clipping: float|null, quality_iso: float|null} $qualityParams
     */
    private function hasQualitySignal(array $qualityParams): bool
    {
        if ($qualityParams['quality_avg'] >= 0.6) {
            return true;
        }

        $aesthetics = $qualityParams['aesthetics_score'];
        if ($aesthetics !== null && $aesthetics >= 0.6) {
            return true;
        }

        return false;
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
