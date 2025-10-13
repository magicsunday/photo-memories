<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_map;
use function is_array;
use function max;
use function min;
use function str_starts_with;

final class ClusterPriorityConfigurationTest extends TestCase
{
    private const PARAMETERS_FILE = __DIR__ . '/../../../config/parameters.yaml';

    #[Test]
    public function requestedHighlightOrderIsRespected(): void
    {
        $priorities       = $this->priorities();
        $vacation         = $this->priorityFor($priorities, 'memories.cluster.priority.vacation_cluster_strategy');
        $weekendOverYears = $this->priorityFor($priorities, 'memories.cluster.priority.weekend_getaways_over_years_cluster_strategy');
        $yearInReview     = $this->priorityFor($priorities, 'memories.cluster.priority.year_in_review_cluster_strategy');
        $monthlyHighlights = $this->priorityFor($priorities, 'memories.cluster.priority.monthly_highlights_cluster_strategy');
        $thisMonthOverYears = $this->priorityFor($priorities, 'memories.cluster.priority.this_month_over_years_cluster_strategy');
        $onThisDay        = $this->priorityFor($priorities, 'memories.cluster.priority.on_this_day_over_years_cluster_strategy');

        self::assertGreaterThan($weekendOverYears, $vacation);
        self::assertGreaterThan($yearInReview, $weekendOverYears);
        self::assertGreaterThan($monthlyHighlights, $yearInReview);
        self::assertGreaterThan($thisMonthOverYears, $monthlyHighlights);
        self::assertGreaterThanOrEqual($onThisDay, $thisMonthOverYears);
        self::assertGreaterThan($onThisDay, $monthlyHighlights);
    }

    #[Test]
    public function onThisDayTierStillOutranksSceneTier(): void
    {
        $priorities = $this->priorities();
        $onThisDay  = $this->priorityFor($priorities, 'memories.cluster.priority.on_this_day_over_years_cluster_strategy');

        $sceneKeys = [
            'memories.cluster.priority.person_cohort_cluster_strategy',
            'memories.cluster.priority.anniversary_cluster_strategy',
            'memories.cluster.priority.holiday_event_cluster_strategy',
            'memories.cluster.priority.new_year_eve_cluster_strategy',
            'memories.cluster.priority.cityscape_night_cluster_strategy',
            'memories.cluster.priority.nightlife_event_cluster_strategy',
            'memories.cluster.priority.hike_adventure_cluster_strategy',
            'memories.cluster.priority.snow_vacation_over_years_cluster_strategy',
            'memories.cluster.priority.season_over_years_cluster_strategy',
            'memories.cluster.priority.season_cluster_strategy',
            'memories.cluster.priority.golden_hour_cluster_strategy',
            'memories.cluster.priority.snow_day_cluster_strategy',
            'memories.cluster.priority.day_album_cluster_strategy',
            'memories.cluster.priority.at_home_weekend_cluster_strategy',
            'memories.cluster.priority.at_home_weekday_cluster_strategy',
        ];

        $sceneValues = array_map(
            fn (string $key): int => $this->priorityFor($priorities, $key),
            $sceneKeys,
        );

        $highestScene = max($sceneValues);

        self::assertGreaterThan($highestScene, $onThisDay);
    }

    #[Test]
    public function sceneTierBeatsDeviceAndSimilarityTier(): void
    {
        $priorities = $this->priorities();

        $sceneKeys = [
            'memories.cluster.priority.person_cohort_cluster_strategy',
            'memories.cluster.priority.anniversary_cluster_strategy',
            'memories.cluster.priority.holiday_event_cluster_strategy',
            'memories.cluster.priority.new_year_eve_cluster_strategy',
            'memories.cluster.priority.cityscape_night_cluster_strategy',
            'memories.cluster.priority.nightlife_event_cluster_strategy',
            'memories.cluster.priority.hike_adventure_cluster_strategy',
            'memories.cluster.priority.snow_vacation_over_years_cluster_strategy',
            'memories.cluster.priority.season_over_years_cluster_strategy',
            'memories.cluster.priority.season_cluster_strategy',
            'memories.cluster.priority.golden_hour_cluster_strategy',
            'memories.cluster.priority.snow_day_cluster_strategy',
            'memories.cluster.priority.day_album_cluster_strategy',
            'memories.cluster.priority.at_home_weekend_cluster_strategy',
            'memories.cluster.priority.at_home_weekday_cluster_strategy',
        ];

        $deviceKeys = [
            'memories.cluster.priority.location_similarity_strategy',
            'memories.cluster.priority.cross_dimension_cluster_strategy',
            'memories.cluster.priority.time_similarity_strategy',
            'memories.cluster.priority.photo_motif_cluster_strategy',
            'memories.cluster.priority.panorama_cluster_strategy',
            'memories.cluster.priority.panorama_over_years_cluster_strategy',
            'memories.cluster.priority.portrait_orientation_cluster_strategy',
            'memories.cluster.priority.video_stories_cluster_strategy',
            'memories.cluster.priority.device_similarity_strategy',
            'memories.cluster.priority.phash_similarity_strategy',
            'memories.cluster.priority.burst_cluster_strategy',
        ];

        $sceneValues = array_map(
            fn (string $key): int => $this->priorityFor($priorities, $key),
            $sceneKeys,
        );
        $deviceValues = array_map(
            fn (string $key): int => $this->priorityFor($priorities, $key),
            $deviceKeys,
        );

        $lowestScene = min($sceneValues);
        $highestDevice = max($deviceValues);

        self::assertGreaterThan($highestDevice, $lowestScene);
    }

    #[Test]
    public function deviceSimilarityRemainsSpecialCasedDuringConsolidation(): void
    {
        $parameters    = $this->parameters();
        $annotateOnly  = $parameters['memories.cluster.consolidate.annotate_only'] ?? null;
        $minUniqueShare = $parameters['memories.cluster.consolidate.min_unique_share'] ?? null;

        self::assertIsArray($annotateOnly);
        self::assertContains('device_similarity', $annotateOnly);

        self::assertIsArray($minUniqueShare);
        self::assertArrayHasKey('device_similarity', $minUniqueShare);
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        $config = Yaml::parseFile(self::PARAMETERS_FILE);
        self::assertIsArray($config);

        $parameters = $config['parameters'] ?? null;
        self::assertIsArray($parameters);

        return $parameters;
    }

    /**
     * @return array<string, int>
     */
    private function priorities(): array
    {
        $result     = [];
        $parameters = $this->parameters();

        foreach ($parameters as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!str_starts_with($key, 'memories.cluster.priority.')) {
                continue;
            }

            $result[$key] = (int) $value;
        }

        return $result;
    }

    /**
     * @param array<string, int> $priorities
     */
    private function priorityFor(array $priorities, string $key): int
    {
        self::assertArrayHasKey($key, $priorities);

        return $priorities[$key];
    }
}
