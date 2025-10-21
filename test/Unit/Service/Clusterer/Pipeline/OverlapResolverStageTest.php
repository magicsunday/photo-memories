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
use MagicSunday\Memories\Service\Clusterer\Pipeline\OverlapResolverStage;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

final class OverlapResolverStageTest extends TestCase
{
    #[Test]
    public function dedupesLowerPriorityWithinSameAlgorithmAndLogsDecision(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $dayTrip  = $this->createDraft('vacation', [1, 2, 3], 0.6, 'day_trip');
        $hike     = $this->createDraft('hike_adventure', [5, 6, 7], 0.7, null);
        $city     = $this->createDraft('significant_place', [8, 9], 0.5, null);

        $result = $stage->process([
            $vacation,
            $dayTrip,
            $hike,
            $city,
        ]);

        self::assertCount(3, $result);
        $kept = $result[0];
        self::assertSame('vacation', $kept->getAlgorithm());
        $merges = $kept->getParams()['meta']['merges'] ?? [];
        self::assertNotSame([], $merges);
        self::assertSame('dedupe', $merges[0]['decision']);
    }

    #[Test]
    public function dropsLowerPriorityOnSevereCrossAlgorithmOverlap(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4, 5, 6], 0.9, 'vacation');
        $hike     = $this->createDraft('hike_adventure', [1, 2, 3, 4, 5, 6, 7], 0.7, null);

        $result = $stage->process([
            $hike,
            $vacation,
        ]);

        self::assertCount(1, $result);
        $kept = $result[0];
        self::assertSame('vacation', $kept->getAlgorithm());
        $merges = $kept->getParams()['meta']['merges'] ?? [];
        self::assertNotSame([], $merges);
        self::assertSame('dedupe', $merges[0]['decision']);
    }

    #[Test]
    public function ignoresSubStoriesDuringOverlapResolution(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'significant_place']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $chapter  = $this->createDraft('significant_place', [1, 2, 3], 0.6, null);
        $chapter->setParam('is_sub_story', true);
        $chapter->setParam('sub_story_priority', 1);
        $chapter->setParam('sub_story_of', ['algorithm' => 'vacation', 'fingerprint' => sha1('1,2,3,4'), 'priority' => 2]);

        $result = $stage->process([
            $vacation,
            $chapter,
        ]);

        self::assertSame([
            $vacation,
            $chapter,
        ], $result);
    }

    #[Test]
    public function mergesCompatibleDraftsAndExtendsMetadata(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $primary = $this->createDraft(
            'vacation',
            [10, 11, 12],
            0.82,
            'vacation',
            [
                'time_range' => ['from' => 1_000, 'to' => 3_600],
                'primaryStaypoint' => ['lat' => 48.13, 'lon' => 11.58],
                'member_selection' => ['hash_samples' => ['abcd1234abcd1234', 'abcd1234ffff0000']],
                'meta' => ['merges' => []],
            ],
        );

        $secondary = $this->createDraft(
            'vacation',
            [11, 12, 13],
            0.8,
            'vacation',
            [
                'time_range' => ['from' => 1_600, 'to' => 3_800],
                'primaryStaypoint' => ['lat' => 48.1301, 'lon' => 11.5802],
                'member_selection' => ['hash_samples' => ['abcd1234aaaa0000']],
            ],
        );

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        self::assertCount(1, $result);
        $merged = $result[0];
        self::assertSame([10, 11, 12, 13], $merged->getMembers());

        $params = $merged->getParams();
        self::assertSame(1_000, $params['time_range']['from']);
        self::assertSame(3_800, $params['time_range']['to']);
        self::assertArrayHasKey('member_selection', $params);
        self::assertCount(3, $params['member_selection']['hash_samples']);

        $merges = $params['meta']['merges'] ?? [];
        self::assertNotSame([], $merges);
        $entry = $merges[0];
        self::assertSame('merge', $entry['decision']);
        self::assertSame('winner', $entry['role']);
    }

    #[Test]
    public function emitsTelemetryForOverlapResolution(): void
    {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure'], $emitter);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $dayTrip  = $this->createDraft('vacation', [1, 2, 3], 0.6, 'day_trip');
        $hike     = $this->createDraft('hike_adventure', [5, 6, 7], 0.7, null);
        $city     = $this->createDraft('significant_place', [8, 9], 0.5, null);

        $stage->process([
            $vacation,
            $dayTrip,
            $hike,
            $city,
        ]);

        self::assertCount(2, $emitter->events);
        $start = $emitter->events[0];
        self::assertSame('overlap_resolver', $start['job']);
        self::assertSame('selection_start', $start['status']);
        self::assertSame(4, $start['context']['pre_count']);

        $completed = $emitter->events[1];
        self::assertSame('overlap_resolver', $completed['job']);
        self::assertSame('selection_completed', $completed['status']);
        self::assertSame(4, $completed['context']['pre_count']);
        self::assertSame(3, $completed['context']['post_count']);
        self::assertSame(1, $completed['context']['dropped_count']);
        self::assertSame(1, $completed['context']['resolved_drops']);
    }

    #[Test]
    public function keepsCrossAlgorithmOverlapBelowDropThreshold(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4, 5], 0.92, 'vacation');
        $hike     = $this->createDraft('hike_adventure', [1, 2, 3, 4, 6], 0.8, null);

        $result = $stage->process([
            $vacation,
            $hike,
        ]);

        self::assertSame([
            $vacation,
            $hike,
        ], $result);
    }

    /**
     * @param array{from: int, to: int} $winnerRange
     * @param array{from: int, to: int} $loserRange
     */
    #[Test]
    #[DataProvider('temporalBoundaryProvider')]
    public function resolvesBasedOnTemporalIouBoundaries(
        array $winnerRange,
        array $loserRange,
        float $expectedTemporalIou,
        bool $expectMerge,
    ): void {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure'], $emitter);

        $primaryMembers   = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $secondaryMembers = [1, 2, 3, 4, 5, 6, 7, 8, 11, 12];

        $primary = $this->createDraft('vacation', $primaryMembers, 0.92, 'vacation', [
            'time_range' => $winnerRange,
        ]);
        $secondary = $this->createDraft('vacation', $secondaryMembers, 0.75, 'vacation', [
            'time_range' => $loserRange,
        ]);

        $metrics = $this->assertShouldMergeDecision($stage, $primary, $secondary, $expectMerge);
        self::assertEqualsWithDelta($expectedTemporalIou, (float) $metrics['temporal_iou'], 1e-9);

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        $this->assertScenarioOutcome(
            $result,
            $primaryMembers,
            $secondaryMembers,
            $expectMerge,
            'temporal_iou',
            $expectedTemporalIou,
            $emitter,
            1e-9,
        );
    }

    /**
     * @param array{from: int, to: int} $timeRange
     */
    #[Test]
    #[DataProvider('spatialDistanceBoundaryProvider')]
    public function resolvesBasedOnSpatialDistanceBoundaries(
        array $timeRange,
        float $loserLongitude,
        float $expectedDistance,
        bool $expectMerge,
    ): void {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new OverlapResolverStage(0.45, 0.85, ['vacation'], $emitter);

        $primaryMembers   = [1, 2, 3, 4, 5, 6, 7, 8];
        $secondaryMembers = [1, 2, 3, 4, 5, 9, 10, 11];

        $primary = $this->createDraft('vacation', $primaryMembers, 1.1, 'vacation', [
            'time_range'        => $timeRange,
            'primaryStaypoint'  => ['lat' => 0.0, 'lon' => 0.0],
        ]);
        $secondary = $this->createDraft('vacation', $secondaryMembers, 0.95, 'vacation', [
            'time_range'        => $timeRange,
            'primaryStaypoint'  => ['lat' => 0.0, 'lon' => $loserLongitude],
        ]);

        $metrics = $this->assertShouldMergeDecision($stage, $primary, $secondary, $expectMerge);
        self::assertNotNull($metrics['spatial_distance_m']);
        self::assertEqualsWithDelta($expectedDistance, (float) $metrics['spatial_distance_m'], 0.05);

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        $this->assertScenarioOutcome(
            $result,
            $primaryMembers,
            $secondaryMembers,
            $expectMerge,
            'spatial_distance_m',
            $expectedDistance,
            $emitter,
            0.05,
        );
    }

    #[Test]
    #[DataProvider('coreSimilarityBoundaryProvider')]
    public function resolvesBasedOnCoreSimilarityBoundaries(
        array $winnerCore,
        array $loserCore,
        float $expectedSimilarity,
        bool $expectMerge,
    ): void {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new OverlapResolverStage(0.45, 0.85, ['vacation'], $emitter);

        $primaryMembers   = [1, 2, 3, 4, 5, 6];
        $secondaryMembers = [1, 2, 3, 4, 7, 8];

        $primary = $this->createDraft('vacation', $primaryMembers, 1.2, 'vacation', [
            'time_range'      => ['from' => 10, 'to' => 110],
            'member_selection' => ['summary' => ['core_members' => $winnerCore]],
        ]);
        $secondary = $this->createDraft('vacation', $secondaryMembers, 0.9, 'vacation', [
            'time_range'      => ['from' => 10, 'to' => 110],
            'member_selection' => ['summary' => ['core_members' => $loserCore]],
        ]);

        $metrics = $this->assertShouldMergeDecision($stage, $primary, $secondary, $expectMerge);
        self::assertNotNull($metrics['core_similarity']);
        self::assertEqualsWithDelta($expectedSimilarity, (float) $metrics['core_similarity'], 1e-9);

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        $this->assertScenarioOutcome(
            $result,
            $primaryMembers,
            $secondaryMembers,
            $expectMerge,
            'core_similarity',
            $expectedSimilarity,
            $emitter,
            1e-9,
        );
    }

    #[Test]
    #[DataProvider('phashBoundaryProvider')]
    public function resolvesBasedOnPhashDeltaBoundaries(
        string $loserHash,
        float $expectedDelta,
        bool $expectMerge,
    ): void {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new OverlapResolverStage(0.45, 0.85, ['vacation'], $emitter);

        $primaryMembers   = [1, 2, 3, 4, 5, 6];
        $secondaryMembers = [1, 2, 3, 4, 7, 8];

        $primary = $this->createDraft('vacation', $primaryMembers, 1.05, 'vacation', [
            'time_range'       => ['from' => 100, 'to' => 300],
            'member_selection' => ['hash_samples' => ['0000000000000000']],
        ]);
        $secondary = $this->createDraft('vacation', $secondaryMembers, 0.98, 'vacation', [
            'time_range'       => ['from' => 110, 'to' => 290],
            'member_selection' => ['hash_samples' => [$loserHash]],
        ]);

        $metrics = $this->assertShouldMergeDecision($stage, $primary, $secondary, $expectMerge);
        self::assertNotNull($metrics['phash_delta']);
        self::assertEqualsWithDelta($expectedDelta, (float) $metrics['phash_delta'], 1e-18);

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        $this->assertScenarioOutcome(
            $result,
            $primaryMembers,
            $secondaryMembers,
            $expectMerge,
            'phash_delta',
            $expectedDelta,
            $emitter,
            1e-18,
        );
    }

    #[Test]
    #[DataProvider('scoreGapBoundaryProvider')]
    public function resolvesBasedOnScoreGapBoundaries(
        float $winnerScore,
        float $loserScore,
        float $expectedGap,
        bool $expectMerge,
    ): void {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new OverlapResolverStage(0.45, 0.85, ['vacation'], $emitter);

        $primaryMembers   = [1, 2, 3, 4, 5, 6];
        $secondaryMembers = [1, 2, 3, 4, 7, 8];

        $primary = $this->createDraft('vacation', $primaryMembers, $winnerScore, 'vacation', [
            'time_range' => ['from' => 50, 'to' => 250],
        ]);
        $secondary = $this->createDraft('vacation', $secondaryMembers, $loserScore, 'vacation', [
            'time_range' => ['from' => 60, 'to' => 240],
        ]);

        $metrics = $this->assertShouldMergeDecision($stage, $primary, $secondary, $expectMerge);
        self::assertEqualsWithDelta($expectedGap, (float) $metrics['score_gap'], 1e-9);

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        $this->assertScenarioOutcome(
            $result,
            $primaryMembers,
            $secondaryMembers,
            $expectMerge,
            'score_gap',
            $expectedGap,
            $emitter,
            1e-9,
        );
    }

    /** @return iterable<string, array{array{from: int, to: int}, array{from: int, to: int}, float, bool}> */
    public static function temporalBoundaryProvider(): iterable
    {
        yield 'temporal_iou_at_threshold' => [
            ['from' => 0, 'to' => 80],
            ['from' => 25, 'to' => 100],
            0.55,
            true,
        ];

        yield 'temporal_iou_just_below_threshold' => [
            ['from' => 0, 'to' => 80],
            ['from' => 26, 'to' => 100],
            0.54,
            false,
        ];
    }

    /** @return iterable<string, array{array{from: int, to: int}, float, float, bool}> */
    public static function spatialDistanceBoundaryProvider(): iterable
    {
        yield 'spatial_distance_at_threshold' => [
            ['from' => 0, 'to' => 120],
            0.22483040147968,
            25_000.0,
            true,
        ];

        yield 'spatial_distance_just_above_threshold' => [
            ['from' => 0, 'to' => 120],
            0.22493040147968,
            25_011.119492664166,
            false,
        ];
    }

    /** @return iterable<string, array{list<string>, list<string>, float, bool}> */
    public static function coreSimilarityBoundaryProvider(): iterable
    {
        $commonExact = ['common-a', 'common-b'];

        $winnerExact = array_merge($commonExact, ['winner-only']);
        $loserExact  = array_merge($commonExact, ['loser-only']);

        $commonNear = [];
        for ($i = 0; $i < 49; ++$i) {
            $commonNear[] = 'common-' . $i;
        }

        $winnerNear = $commonNear;
        for ($i = 0; $i < 26; ++$i) {
            $winnerNear[] = 'winner-' . $i;
        }

        $loserNear = $commonNear;
        for ($i = 0; $i < 25; ++$i) {
            $loserNear[] = 'loser-' . $i;
        }

        yield 'core_similarity_at_threshold' => [
            $winnerExact,
            $loserExact,
            0.5,
            true,
        ];

        yield 'core_similarity_just_below_threshold' => [
            $winnerNear,
            $loserNear,
            0.49,
            false,
        ];
    }

    /** @return iterable<string, array{string, float, bool}> */
    public static function phashBoundaryProvider(): iterable
    {
        $baseThreshold  = '2e147ae147ae1400';
        $belowThreshold = '2e147ae047ae1400';
        $aboveThreshold = '2e147ae247ae1400';

        yield 'phash_delta_at_threshold' => [
            $baseThreshold,
            0.18,
            true,
        ];

        yield 'phash_delta_just_below_threshold' => [
            $belowThreshold,
            0.17999999976716935,
            true,
        ];

        yield 'phash_delta_just_above_threshold' => [
            $aboveThreshold,
            0.18000000023283064,
            false,
        ];
    }

    /** @return iterable<string, array{float, float, float, bool}> */
    public static function scoreGapBoundaryProvider(): iterable
    {
        yield 'score_gap_at_threshold' => [
            1.0,
            0.65,
            0.35,
            true,
        ];

        yield 'score_gap_at_threshold_with_subunit_winner' => [
            0.8,
            0.45,
            0.35,
            true,
        ];

        yield 'score_gap_just_above_threshold' => [
            1.0,
            0.649,
            0.351,
            false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertShouldMergeDecision(OverlapResolverStage $stage, ClusterDraft $winner, ClusterDraft $loser, bool $expected): array
    {
        $metrics = $this->collectMetricsForPair($stage, $winner, $loser);

        $shouldMerge = $this->invokeShouldMerge($stage, $metrics);
        self::assertSame($expected, $shouldMerge);

        return $metrics;
    }

    /**
     * @return array<string, float|int|bool|null>
     */
    private function collectMetricsForPair(OverlapResolverStage $stage, ClusterDraft $winner, ClusterDraft $loser): array
    {
        $normalizedWinner = $this->normalizeMembersList($winner->getMembers());
        $normalizedLoser  = $this->normalizeMembersList($loser->getMembers());

        $memberIou = $this->computeJaccardIndex($normalizedWinner, $normalizedLoser);

        $collectMetrics = new ReflectionMethod(OverlapResolverStage::class, 'collectMetrics');
        $collectMetrics->setAccessible(true);

        /** @var array<string, float|int|bool|null> $metrics */
        $metrics = $collectMetrics->invoke($stage, $winner, $loser, $memberIou);

        return $metrics;
    }

    private function invokeShouldMerge(OverlapResolverStage $stage, array $metrics): bool
    {
        $shouldMerge = new ReflectionMethod(OverlapResolverStage::class, 'shouldMerge');
        $shouldMerge->setAccessible(true);

        /** @var bool $result */
        $result = $shouldMerge->invoke($stage, $metrics);

        return $result;
    }

    /**
     * @param list<int> $primaryMembers
     * @param list<int> $secondaryMembers
     * @param list<ClusterDraft> $result
     */
    private function assertScenarioOutcome(
        array $result,
        array $primaryMembers,
        array $secondaryMembers,
        bool $expectMerge,
        string $metricKey,
        float $expectedMetric,
        RecordingMonitoringEmitter $emitter,
        float $delta,
    ): void {
        self::assertCount(1, $result);

        $winner = $result[0];
        $entry  = $this->extractLatestMergeEntry($winner);

        self::assertSame('winner', $entry['role']);
        self::assertSame($expectMerge ? 'merge' : 'dedupe', $entry['decision']);
        self::assertArrayHasKey('metrics', $entry);
        $metrics = $entry['metrics'];
        self::assertArrayHasKey($metricKey, $metrics);
        self::assertEqualsWithDelta($expectedMetric, (float) $metrics[$metricKey], $delta);

        $expectedMembers = $expectMerge
            ? $this->expectedMergedMembers($primaryMembers, $secondaryMembers)
            : $primaryMembers;

        self::assertSame($expectedMembers, $winner->getMembers());

        $this->assertTelemetryForBoundary($emitter);
    }

    private function extractLatestMergeEntry(ClusterDraft $draft): array
    {
        $params = $draft->getParams();
        $meta   = $params['meta'] ?? [];
        self::assertIsArray($meta);

        $merges = $meta['merges'] ?? [];
        self::assertIsArray($merges);
        self::assertNotSame([], $merges);

        /** @var array<string, mixed> $entry */
        $entry = $merges[array_key_last($merges)];

        return $entry;
    }

    private function expectedMergedMembers(array $primaryMembers, array $secondaryMembers): array
    {
        $merged = $primaryMembers;

        foreach ($secondaryMembers as $member) {
            if (!in_array($member, $merged, true)) {
                $merged[] = $member;
            }
        }

        return $merged;
    }

    private function assertTelemetryForBoundary(RecordingMonitoringEmitter $emitter): void
    {
        self::assertCount(2, $emitter->events);

        $start = $emitter->events[0];
        self::assertSame('overlap_resolver', $start['job']);
        self::assertSame('selection_start', $start['status']);
        self::assertSame(2, $start['context']['pre_count'] ?? null);
        self::assertArrayHasKey('merge_threshold', $start['context']);
        self::assertArrayHasKey('drop_threshold', $start['context']);

        $completed = $emitter->events[1];
        self::assertSame('overlap_resolver', $completed['job']);
        self::assertSame('selection_completed', $completed['status']);
        self::assertSame(2, $completed['context']['pre_count'] ?? null);
        self::assertSame(1, $completed['context']['post_count'] ?? null);
        self::assertSame(1, $completed['context']['dropped_count'] ?? null);
        self::assertSame(1, $completed['context']['resolved_drops'] ?? null);
    }

    /**
     * @param list<int> $members
     *
     * @return list<int>
     */
    private function normalizeMembersList(array $members): array
    {
        $unique = array_values(array_unique($members));
        sort($unique);

        return $unique;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private function computeJaccardIndex(array $a, array $b): float
    {
        $ia    = 0;
        $ib    = 0;
        $inter = 0;
        $na    = count($a);
        $nb    = count($b);

        while ($ia < $na && $ib < $nb) {
            $va = $a[$ia];
            $vb = $b[$ib];
            if ($va === $vb) {
                ++$inter;
                ++$ia;
                ++$ib;
                continue;
            }

            if ($va < $vb) {
                ++$ia;
                continue;
            }

            ++$ib;
        }

        $union = $na + $nb - $inter;

        return $union > 0 ? $inter / (float) $union : 0.0;
    }

    /**
     * @param list<int> $members
     */
    private function createDraft(
        string $algorithm,
        array $members,
        float $score,
        ?string $classification,
        array $extraParams = [],
    ): ClusterDraft {
        $params = ['score' => $score];
        if ($classification !== null) {
            $params['classification'] = $classification;
        }

        $params = array_merge($params, $extraParams);

        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );
    }
}
