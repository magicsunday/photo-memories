<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Pipeline\DominanceSelectionStage;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DominanceSelectionStageTest extends TestCase
{
    #[Test]
    public function prefersPriorityAlgorithmsAndSuppressesOverlap(): void
    {
        $stage = new DominanceSelectionStage(
            overlapMergeThreshold: 0.45,
            overlapDropThreshold: 0.85,
            keepOrder: ['primary', 'secondary'],
            classificationPriority: [],
        );

        $primaryWinner    = $this->createDraft('primary', 0.8, [1, 2, 3]);
        $secondaryOverlap = $this->createDraft('secondary', 0.95, [2, 3, 4]);
        $tertiary         = $this->createDraft('tertiary', 0.7, [10, 11]);
        $secondaryUnique  = $this->createDraft('secondary', 0.6, [5, 6, 7]);

        $result = $stage->process([
            $primaryWinner,
            $secondaryOverlap,
            $tertiary,
            $secondaryUnique,
        ]);

        self::assertSame([
            $primaryWinner,
            $secondaryUnique,
            $tertiary,
        ], $result);
    }

    #[Test]
    public function prefersVacationClassificationBeforeSize(): void
    {
        $stage = new DominanceSelectionStage(
            overlapMergeThreshold: 0.45,
            overlapDropThreshold: 0.85,
            keepOrder: ['vacation'],
            classificationPriority: [
                'vacation' => [
                    'vacation',
                    'weekend_getaway',
                    'short_trip',
                    'day_trip',
                    'weekend_over_years',
                    'transit',
                    'location',
                    'device',
                ],
            ],
        );

        $vacation         = $this->createDraft('vacation', 0.8, [1, 2], 'vacation');
        $weekendGetaway   = $this->createDraft('vacation', 0.8, [5, 6, 7], 'weekend_getaway');
        $shortTrip        = $this->createDraft('vacation', 0.8, [10, 11, 12, 13], 'short_trip');
        $dayTrip          = $this->createDraft('vacation', 0.8, [20, 21, 22, 23, 24], 'day_trip');
        $weekendOverYears = $this->createDraft('vacation', 0.8, [25, 26, 27, 28], 'weekend_over_years');
        $unclassified     = $this->createDraft('vacation', 0.8, [30, 31, 32]);

        $result = $stage->process([
            $weekendOverYears,
            $shortTrip,
            $vacation,
            $dayTrip,
            $unclassified,
            $weekendGetaway,
        ]);

        self::assertSame([
            $vacation,
            $weekendGetaway,
            $shortTrip,
            $dayTrip,
            $weekendOverYears,
            $unclassified,
        ], $result);
    }

    #[Test]
    public function respectsConfiguredHierarchyAcrossAlgorithms(): void
    {
        $keepOrder = [
            'vacation',
            'weekend_getaways_over_years',
            'transit_travel_day',
            'significant_place',
            'first_visit_place',
            'location_similarity',
            'hike_adventure',
            'snow_vacation_over_years',
            'season',
            'season_over_years',
            'snow_day',
            'golden_hour',
            'holiday_event',
            'new_year_eve',
            'nightlife_event',
            'cityscape_night',
            'anniversary',
            'people_cohort',
            'person_cohort',
            'year_in_review',
            'monthly_highlights',
            'this_month_over_years',
            'on_this_day_over_years',
            'one_year_ago',
            'day_album',
            'time_similarity',
            'at_home_weekend',
            'at_home_weekday',
            'photo_motif',
            'panorama',
            'panorama_over_years',
            'portrait_orientation',
            'video_stories',
            'cross_dimension',
            'device_similarity',
            'phash_similarity',
            'burst',
        ];

        $stage = new DominanceSelectionStage(
            overlapMergeThreshold: 0.45,
            overlapDropThreshold: 0.85,
            keepOrder: $keepOrder,
            classificationPriority: [
                'vacation' => [
                    'vacation',
                    'weekend_getaway',
                    'short_trip',
                    'day_trip',
                    'weekend_over_years',
                    'transit',
                    'location',
                    'device',
                ],
                'device_similarity' => ['device', 'phash_similarity'],
            ],
        );

        $device   = $this->createDraft('device_similarity', 0.95, [70, 71]);
        $vacation = $this->createDraft('vacation', 0.6, [10, 11], 'vacation');
        $monthly  = $this->createDraft('monthly_highlights', 0.8, [30, 31]);
        $hike     = $this->createDraft('hike_adventure', 0.7, [20, 21]);
        $person   = $this->createDraft('person_cohort', 0.9, [40, 41]);
        $home     = $this->createDraft('at_home_weekday', 0.85, [50, 51]);
        $panorama = $this->createDraft('panorama', 0.65, [60, 61]);
        $cross    = $this->createDraft('cross_dimension', 0.5, [80, 81]);

        $result = $stage->process([
            $device,
            $monthly,
            $vacation,
            $hike,
            $person,
            $home,
            $panorama,
            $cross,
        ]);

        self::assertSame([
            $vacation,
            $hike,
            $person,
            $monthly,
            $home,
            $panorama,
            $cross,
            $device,
        ], $result);
    }

    #[Test]
    public function keepsSubStoriesEvenWithHighOverlap(): void
    {
        $stage = new DominanceSelectionStage(
            overlapMergeThreshold: 0.45,
            overlapDropThreshold: 0.85,
            keepOrder: ['primary', 'secondary'],
            classificationPriority: [],
        );

        $primary  = $this->createDraft('primary', 0.85, [1, 2, 3]);
        $subStory = $this->createDraft('secondary', 0.7, [1, 2, 3]);
        $subStory->setParam('is_sub_story', true);
        $subStory->setParam('sub_story_priority', 1);
        $subStory->setParam('sub_story_of', ['algorithm' => 'primary', 'fingerprint' => sha1('1,2,3'), 'priority' => 2]);

        $result = $stage->process([
            $primary,
            $subStory,
        ]);

        self::assertSame([
            $primary,
            $subStory,
        ], $result);
    }

    #[Test]
    public function emitsTelemetryForDominanceDecisions(): void
    {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new DominanceSelectionStage(
            overlapMergeThreshold: 0.45,
            overlapDropThreshold: 0.85,
            keepOrder: ['primary', 'secondary'],
            classificationPriority: [],
            monitoringEmitter: $emitter,
        );

        $primary = $this->createDraft('primary', 0.85, [1, 2, 3]);
        $secondaryOverlap = $this->createDraft('secondary', 0.9, [1, 2, 3]);
        $subStory = $this->createDraft('secondary', 0.6, [4, 5]);
        $subStory->setParam('is_sub_story', true);
        $subStory->setParam('sub_story_priority', 1);
        $subStory->setParam('sub_story_of', ['algorithm' => 'primary', 'fingerprint' => sha1('1,2,3'), 'priority' => 2]);

        $stage->process([
            $primary,
            $secondaryOverlap,
            $subStory,
        ]);

        self::assertCount(2, $emitter->events);

        $start = $emitter->events[0];
        self::assertSame('dominance_selection', $start['job']);
        self::assertSame('selection_start', $start['status']);
        self::assertSame(3, $start['context']['pre_count']);

        $completed = $emitter->events[1];
        self::assertSame('dominance_selection', $completed['job']);
        self::assertSame('selection_completed', $completed['status']);
        self::assertSame(3, $completed['context']['pre_count']);
        self::assertSame(2, $completed['context']['post_count']);
        self::assertSame(1, $completed['context']['rejected_candidates']);
        self::assertSame(1, $completed['context']['sub_story_count']);
    }

    #[Test]
    public function allowsOverlapJustBelowMergeThreshold(): void
    {
        $stage = new DominanceSelectionStage(
            overlapMergeThreshold: 0.45,
            overlapDropThreshold: 0.85,
            keepOrder: ['primary', 'secondary'],
            classificationPriority: [],
        );

        $primary   = $this->createDraft('primary', 0.8, [1, 2, 3, 4, 5]);
        $secondary = $this->createDraft('secondary', 0.7, [3, 4, 5, 6, 7]);

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        self::assertSame([
            $primary,
            $secondary,
        ], $result);
    }

    /**
     * @param list<int> $members
     */
    private function createDraft(string $algorithm, float $score, array $members, ?string $classification = null): ClusterDraft
    {
        $params = ['score' => $score];
        if ($classification !== null) {
            $params['classification'] = $classification;
        }

        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );
    }
}
