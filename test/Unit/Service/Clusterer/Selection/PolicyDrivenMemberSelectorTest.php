<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Selection;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionContext;
use MagicSunday\Memories\Service\Clusterer\Selection\PolicyDrivenMemberSelector;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\OrientationBalanceStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\PeopleBalanceStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\PhashDiversityStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\SceneDiversityStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\SelectionStageInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\StaypointQuotaStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\TimeGapStage;
use MagicSunday\Memories\Test\Support\EntityIdAssignmentTrait;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

final class PolicyDrivenMemberSelectorTest extends TestCase
{
    use EntityIdAssignmentTrait;

    #[Test]
    public function stagesAreRegisteredInExpectedOrder(): void
    {
        $selector = $this->createSelector();

        $hardProperty = new ReflectionProperty(PolicyDrivenMemberSelector::class, 'hardStages');
        $softProperty = new ReflectionProperty(PolicyDrivenMemberSelector::class, 'softStages');
        $hardProperty->setAccessible(true);
        $softProperty->setAccessible(true);

        $hard = $hardProperty->getValue($selector);
        $soft = $softProperty->getValue($selector);

        self::assertSame([
            TimeGapStage::class,
            StaypointQuotaStage::class,
            PhashDiversityStage::class,
        ], array_map(static fn (object $stage): string => $stage::class, $hard));

        self::assertSame([
            SceneDiversityStage::class,
            OrientationBalanceStage::class,
            PeopleBalanceStage::class,
        ], array_map(static fn (object $stage): string => $stage::class, $soft));
    }

    #[Test]
    public function telemetryCollectsRejectionsForVacationPolicy(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();
        $mediaMap = $this->mediaFixtures();
        $memberIds = array_keys($mediaMap);

        $context = new MemberSelectionContext(
            $this->createDraft('vacation', $memberIds, 'vacation.transit'),
            $policy,
            $mediaMap,
            $this->qualityScores(),
            [],
        );

        $result = $selector->select('vacation', $memberIds, $context);
        $telemetry = $result->getTelemetry();

        self::assertSame($policy->getProfileKey(), $telemetry['policy']['profile']);
        self::assertSame('vacation.transit', $telemetry['storyline']);
        self::assertSame('vacation.transit', $telemetry['policy']['storyline']);
        self::assertSame(10, $telemetry['counts']['considered']);
        self::assertArrayHasKey('faces', $telemetry['metrics']);

        $rejections = $telemetry['rejections'];
        foreach ([
            'time_gap',
            'day_quota',
            'time_slot',
            'staypoint_quota',
            'phash_similarity',
            'scene_balance',
            'orientation_balance',
            'people_balance',
        ] as $key) {
            self::assertArrayHasKey($key, $rejections);
            self::assertGreaterThanOrEqual(0, $rejections[$key]);
        }

        $expectedSpacing = max(25, (int) floor($policy->getMinSpacingSeconds() * 0.6));

        self::assertGreaterThan(0, $rejections['phash_similarity']);
        self::assertArrayHasKey('relaxations', $telemetry);
        self::assertGreaterThanOrEqual(2, count($telemetry['relaxations']));
        self::assertSame($expectedSpacing, $telemetry['relaxations'][1]['policy']['min_spacing_seconds']);
        self::assertSame($expectedSpacing, $telemetry['policy']['min_spacing_seconds']);
        self::assertArrayHasKey('mmr', $telemetry);
        self::assertNotEmpty($telemetry['mmr']['iterations'] ?? []);
    }

    #[Test]
    public function maximalMarginalRelevancePenalisesNearDuplicates(): void
    {
        $stage = new class implements SelectionStageInterface {
            public function getName(): string
            {
                return 'noop';
            }

            public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
            {
                return $candidates;
            }
        };

        $selector = new PolicyDrivenMemberSelector([$stage], []);

        $policy = new SelectionPolicy(
            profileKey: 'mmr-test',
            targetTotal: 2,
            minimumTotal: 2,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.0,
            spacingProgressFactor: 0.0,
            cohortPenalty: 0.0,
            mmrLambda: 0.45,
            mmrSimilarityFloor: 0.3,
            mmrSimilarityCap: 0.95,
            mmrMaxConsideration: 3,
        );

        $telemetry = new SelectionTelemetry();
        $method    = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runPipeline');
        $method->setAccessible(true);

        $candidates = [
            [
                'id' => 1,
                'score' => 0.96,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 10,
            ],
            [
                'id' => 2,
                'score' => 0.95,
                'hash_bits' => $this->bitsFromHex('fffffffffffffffe'),
                'timestamp' => 20,
            ],
            [
                'id' => 3,
                'score' => 0.7,
                'hash_bits' => $this->bitsFromHex('0000000000000000'),
                'timestamp' => 30,
            ],
        ];

        /** @var list<array<string, mixed>> $result */
        $result = $method->invoke($selector, $candidates, $policy, $telemetry);

        self::assertCount(2, $result);
        self::assertSame([1, 3], array_map(static fn (array $candidate): int => $candidate['id'], $result));

        $mmr = $telemetry->mmrSummary();
        self::assertNotNull($mmr);
        self::assertSame([1, 3], $mmr['selected']);
        self::assertCount(2, $mmr['iterations']);
        $secondIteration = $mmr['iterations'][1];
        self::assertSame(3, $secondIteration['selected']);

        $duplicateEvaluation = null;
        foreach ($secondIteration['evaluations'] as $evaluation) {
            if (($evaluation['id'] ?? null) === 2) {
                $duplicateEvaluation = $evaluation;

                break;
            }
        }

        self::assertNotNull($duplicateEvaluation);
        self::assertGreaterThan(0.0, $duplicateEvaluation['penalty']);
        self::assertFalse($duplicateEvaluation['selected']);
    }

    #[Test]
    public function maximalMarginalRelevanceClampsSimilarityPenaltiesAtFloorAndCap(): void
    {
        $stage = new class implements SelectionStageInterface {
            public function getName(): string
            {
                return 'noop';
            }

            public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
            {
                return $candidates;
            }
        };

        $selector = new PolicyDrivenMemberSelector([$stage], []);

        $policy = new SelectionPolicy(
            profileKey: 'mmr-floor-cap',
            targetTotal: 3,
            minimumTotal: 3,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.0,
            spacingProgressFactor: 0.0,
            cohortPenalty: 0.0,
            mmrLambda: 0.5,
            mmrSimilarityFloor: 0.71875,
            mmrSimilarityCap: 0.75,
            mmrMaxConsideration: 6,
        );

        $method = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runPipeline');
        $method->setAccessible(true);

        $candidates = [
            [
                'id' => 100,
                'score' => 0.95,
                'hash_bits' => $this->bitsFromHex('0000000000000000'),
                'timestamp' => 10,
            ],
            [
                'id' => 101,
                'score' => 0.9,
                'hash_bits' => $this->bitsFromHex('ffffe00000000000'),
                'timestamp' => 20,
            ],
            [
                'id' => 102,
                'score' => 0.89,
                'hash_bits' => $this->bitsFromHex('ffff600000000000'),
                'timestamp' => 30,
            ],
            [
                'id' => 103,
                'score' => 0.88,
                'hash_bits' => $this->bitsFromHex('ffff800000000000'),
                'timestamp' => 40,
            ],
            [
                'id' => 104,
                'score' => 0.87,
                'hash_bits' => $this->bitsFromHex('ffff000000000000'),
                'timestamp' => 50,
            ],
            [
                'id' => 105,
                'score' => 0.86,
                'hash_bits' => $this->bitsFromHex('fffe000000000000'),
                'timestamp' => 60,
            ],
            [
                'id' => 106,
                'score' => 0.2,
                'hash_bits' => $this->bitsFromHex('0000000000000000'),
                'timestamp' => 70,
            ],
        ];

        $telemetry = new SelectionTelemetry();

        /** @var list<array<string, mixed>> $firstRun */
        $firstRun = $method->invoke($selector, $candidates, $policy, $telemetry);

        $summary = $telemetry->mmrSummary();
        self::assertNotNull($summary);
        self::assertSame(6, $summary['max_considered']);
        self::assertSame(6, $summary['pool_size']);
        self::assertGreaterThanOrEqual(2, count($summary['iterations']));

        $secondIteration = $summary['iterations'][1];
        self::assertSame(2, $secondIteration['step']);

        $evaluations = [];
        foreach ($secondIteration['evaluations'] as $evaluation) {
            $evaluations[$evaluation['id']] = $evaluation;
        }

        self::assertArrayHasKey(101, $evaluations);
        self::assertArrayHasKey(102, $evaluations);
        self::assertArrayHasKey(103, $evaluations);
        self::assertArrayHasKey(104, $evaluations);
        self::assertArrayHasKey(105, $evaluations);

        $belowFloor = $evaluations[101];
        self::assertSame(0.703125, $belowFloor['raw_similarity']);
        self::assertSame(0.0, $belowFloor['penalised_similarity']);
        self::assertSame(0.0, $belowFloor['penalty']);
        self::assertNull($belowFloor['reference']);

        $atFloor = $evaluations[102];
        self::assertSame(0.71875, $atFloor['raw_similarity']);
        self::assertSame(0.71875, $atFloor['penalised_similarity']);
        self::assertSame(0.359375, $atFloor['penalty']);
        self::assertSame(100, $atFloor['reference']);

        $aboveFloor = $evaluations[103];
        self::assertSame(0.734375, $aboveFloor['raw_similarity']);
        self::assertSame(0.734375, $aboveFloor['penalised_similarity']);
        self::assertSame(0.3671875, $aboveFloor['penalty']);
        self::assertSame(100, $aboveFloor['reference']);

        $atCap = $evaluations[104];
        self::assertSame(0.75, $atCap['raw_similarity']);
        self::assertSame(0.75, $atCap['penalised_similarity']);
        self::assertSame(0.375, $atCap['penalty']);
        self::assertSame(100, $atCap['reference']);

        $aboveCap = $evaluations[105];
        self::assertSame(0.765625, $aboveCap['raw_similarity']);
        self::assertSame(0.75, $aboveCap['penalised_similarity']);
        self::assertSame(0.375, $aboveCap['penalty']);
        self::assertSame(100, $aboveCap['reference']);

        /** @var list<array<string, mixed>> $secondRun */
        $secondRun = $method->invoke($selector, $candidates, $policy, new SelectionTelemetry());

        self::assertSame(
            array_column($firstRun, 'id'),
            array_column($secondRun, 'id'),
        );
    }

    #[Test]
    public function maximalMarginalRelevancePrefersLowerIdWhenScoresTie(): void
    {
        $selector = new PolicyDrivenMemberSelector(
            hardStages: [
                new class implements SelectionStageInterface {
                    public function getName(): string
                    {
                        return 'noop';
                    }

                    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
                    {
                        return $candidates;
                    }
                },
            ],
            softStages: [],
        );

        $policy = new SelectionPolicy(
            profileKey: 'tie-break-id',
            targetTotal: 1,
            minimumTotal: 1,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.0,
            spacingProgressFactor: 0.0,
            cohortPenalty: 0.0,
            mmrLambda: 1.0,
            mmrSimilarityFloor: 0.0,
            mmrSimilarityCap: 1.0,
            mmrMaxConsideration: 5,
        );

        $telemetry = new SelectionTelemetry();
        $method    = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runPipeline');
        $method->setAccessible(true);

        $candidates = [
            [
                'id' => 99,
                'score' => 0.75,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 1_700_000_100,
            ],
            [
                'id' => 42,
                'score' => 0.75,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 1_700_000_100,
            ],
        ];

        /** @var list<array<string, mixed>> $result */
        $result = $method->invoke($selector, $candidates, $policy, $telemetry);

        self::assertCount(1, $result);
        self::assertSame(42, $result[0]['id']);

        $summary = $telemetry->mmrSummary();
        self::assertNotNull($summary);
        self::assertSame([42], $summary['selected']);
        self::assertSame(5, $summary['max_considered']);
        self::assertSame(2, $summary['pool_size']);
        self::assertArrayHasKey(0, $summary['iterations']);

        $evaluations = [];
        foreach ($summary['iterations'][0]['evaluations'] as $evaluation) {
            $evaluations[$evaluation['id']] = $evaluation;
        }

        self::assertArrayHasKey(42, $evaluations);
        self::assertSame(0.75, $evaluations[42]['score']);
        self::assertSame(0.75, $evaluations[42]['mmr_score']);
        self::assertTrue($evaluations[42]['selected']);

        self::assertArrayHasKey(99, $evaluations);
        self::assertSame(0.75, $evaluations[99]['score']);
        self::assertSame(0.75, $evaluations[99]['mmr_score']);
        self::assertFalse($evaluations[99]['selected']);
    }

    #[Test]
    public function maximalMarginalRelevanceTieRemainsDeterministicWithDifferentTimestamps(): void
    {
        $selector = new PolicyDrivenMemberSelector(
            hardStages: [
                new class implements SelectionStageInterface {
                    public function getName(): string
                    {
                        return 'noop';
                    }

                    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
                    {
                        return $candidates;
                    }
                },
            ],
            softStages: [],
        );

        $policy = new SelectionPolicy(
            profileKey: 'tie-break-timestamp',
            targetTotal: 1,
            minimumTotal: 1,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.0,
            spacingProgressFactor: 0.0,
            cohortPenalty: 0.0,
            mmrLambda: 1.0,
            mmrSimilarityFloor: 0.0,
            mmrSimilarityCap: 1.0,
            mmrMaxConsideration: 4,
        );

        $telemetry = new SelectionTelemetry();
        $method    = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runPipeline');
        $method->setAccessible(true);

        $candidates = [
            [
                'id' => 7,
                'score' => 0.8,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 1_700_000_500,
            ],
            [
                'id' => 8,
                'score' => 0.8,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 1_700_000_100,
            ],
        ];

        /** @var list<array<string, mixed>> $result */
        $result = $method->invoke($selector, $candidates, $policy, $telemetry);

        self::assertCount(1, $result);
        self::assertSame(7, $result[0]['id']);

        $summary = $telemetry->mmrSummary();
        self::assertNotNull($summary);
        self::assertSame([7], $summary['selected']);
        self::assertSame(2, $summary['pool_size']);

        $iteration = $summary['iterations'][0];
        self::assertSame(7, $iteration['selected']);

        $evaluations = [];
        foreach ($iteration['evaluations'] as $evaluation) {
            $evaluations[$evaluation['id']] = $evaluation;
        }

        self::assertArrayHasKey(7, $evaluations);
        self::assertArrayHasKey(8, $evaluations);
        self::assertSame($evaluations[7]['mmr_score'], $evaluations[8]['mmr_score']);
        self::assertSame($evaluations[7]['score'], $evaluations[8]['score']);
        self::assertTrue($evaluations[7]['selected']);
        self::assertFalse($evaluations[8]['selected']);
    }

    #[Test]
    public function maximalMarginalRelevanceKeepsStableOrderWhenPoolExceedsCandidates(): void
    {
        $selector = new PolicyDrivenMemberSelector(
            hardStages: [
                new class implements SelectionStageInterface {
                    public function getName(): string
                    {
                        return 'noop';
                    }

                    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
                    {
                        return $candidates;
                    }
                },
            ],
            softStages: [],
        );

        $policy = new SelectionPolicy(
            profileKey: 'stable-order',
            targetTotal: 3,
            minimumTotal: 3,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.0,
            spacingProgressFactor: 0.0,
            cohortPenalty: 0.0,
            mmrLambda: 0.9,
            mmrSimilarityFloor: 0.0,
            mmrSimilarityCap: 1.0,
            mmrMaxConsideration: 10,
        );

        $telemetry = new SelectionTelemetry();
        $method    = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runPipeline');
        $method->setAccessible(true);

        $candidates = [
            [
                'id' => 3,
                'score' => 0.9,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 100,
            ],
            [
                'id' => 1,
                'score' => 0.85,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 50,
            ],
            [
                'id' => 2,
                'score' => 0.8,
                'hash_bits' => $this->bitsFromHex('ffffffffffffffff'),
                'timestamp' => 75,
            ],
        ];

        /** @var list<array<string, mixed>> $result */
        $result = $method->invoke($selector, $candidates, $policy, $telemetry);

        self::assertCount(3, $result);
        self::assertSame([1, 2, 3], array_column($result, 'id'));

        $summary = $telemetry->mmrSummary();
        self::assertNotNull($summary);
        self::assertSame(10, $summary['max_considered']);
        self::assertSame(3, $summary['pool_size']);
        self::assertSame([3, 1, 2], $summary['selected']);

        $iterations = $summary['iterations'];
        self::assertSame(3, count($iterations));
        self::assertSame([3, 1, 2], array_map(static fn (array $iteration): int => $iteration['selected'], $iterations));
    }

    #[Test]
    public function vacationProfileRelaxationKeepsSixHighQualityMembersPerDay(): void
    {
        $selector = $this->createSelector();

        $raw         = Yaml::parseFile(__DIR__ . '/../../../../../config/parameters/selection.yaml');
        $parameters  = $raw['parameters'];
        $provider    = new SelectionPolicyProvider(
            profiles: $parameters['memories.selection.profiles'],
            algorithmProfiles: $parameters['memories.selection.algorithm_profiles'],
            defaultProfile: $parameters['memories.selection.default_profile'],
        );
        $policy      = $provider->forAlgorithm('vacation');

        self::assertSame(6, $policy->getMaxPerDay());
        self::assertSame(1800, $policy->getMinSpacingSeconds());
        self::assertSame(9, $policy->getPhashMinHamming());
        self::assertSame(0.5, $policy->getQualityFloor());

        $timestamps = [
            1 => '2024-06-01T00:30:00+02:00',
            2 => '2024-06-01T04:30:00+02:00',
            3 => '2024-06-01T08:30:00+02:00',
            4 => '2024-06-01T12:30:00+02:00',
            5 => '2024-06-01T16:30:00+02:00',
            6 => '2024-06-01T20:30:00+02:00',
            7 => '2024-06-01T21:30:00+02:00',
        ];

        $hashes = [
            1 => '0000000000000000',
            2 => 'ffffffffffffffff',
            3 => '0f0f0f0f0f0f0f0f',
            4 => 'f0f0f0f0f0f0f0f0',
            5 => '00ff00ff00ff00ff',
            6 => 'ff00ff00ff00ff00',
            7 => '3333333333333333',
        ];

        $coordinates = [
            1 => [48.100, 11.500],
            2 => [48.104, 11.504],
            3 => [48.108, 11.508],
            4 => [48.112, 11.512],
            5 => [48.116, 11.516],
            6 => [48.120, 11.520],
            7 => [48.124, 11.524],
        ];

        $mediaMap      = [];
        $qualityScores = [];

        foreach ([1, 2, 3, 4, 5, 6] as $id) {
            $media = $this->createMedia($id, $timestamps[$id], $hashes[$id], [], $coordinates[$id], [4000, 3000]);
            $media->setQualityScore(0.55);
            switch ($id) {
                case 1:
                    $media->setFacesCount(4);
                    $media->setHasFaces(true);
                    $media->setFeatures([
                        'faces' => ['largest_coverage' => 0.32],
                    ]);
                    break;
                case 2:
                    $media->setIsPanorama(true);
                    break;
                case 3:
                    $media->setFeatures([
                        'calendar' => ['daypart' => 'night'],
                    ]);
                    break;
                case 4:
                    $media->setSceneTags([
                        ['label' => 'Delicious food spread', 'score' => 0.9],
                    ]);
                    break;
                case 5:
                    $media->setSceneTags([
                        ['label' => 'Historic museum interior', 'score' => 0.88],
                    ]);
                    break;
                default:
                    break;
            }

            if (in_array($id, [3, 5, 6], true)) {
                $media->setWidth(3000);
                $media->setHeight(4000);
                $media->setOrientation(6);
            }
            $mediaMap[$id]      = $media;
            $qualityScores[$id] = 0.55;
        }

        $lowQualityId = 7;
        $lowQuality   = $this->createMedia($lowQualityId, $timestamps[$lowQualityId], $hashes[$lowQualityId], [], $coordinates[$lowQualityId], [4000, 3000]);
        $lowQuality->setQualityScore(0.45);

        $mediaMap[$lowQualityId]      = $lowQuality;
        $qualityScores[$lowQualityId] = 0.45;

        $memberIds = array_keys($mediaMap);
        $draft     = $this->createDraft('vacation', $memberIds, 'vacation.relaxed');

        $context = new MemberSelectionContext($draft, $policy, $mediaMap, $qualityScores, []);
        $result  = $selector->select('vacation', $memberIds, $context);

        $selectedIds = $result->getMemberIds();
        sort($selectedIds);

        $telemetry = $result->getTelemetry();
        self::assertCount(6, $selectedIds);
        self::assertSame([1, 2, 3, 4, 5, 6], $selectedIds);
        self::assertNotContains($lowQualityId, $selectedIds);
        self::assertSame(7, $telemetry['counts']['considered']);
        self::assertSame(6, $telemetry['counts']['selected']);
        self::assertArrayHasKey('quality', $telemetry['rejections']);
        self::assertSame(1, $telemetry['rejections']['quality']);
    }

    #[Test]
    public function telemetryCollectsRejectionsForHighlightsPolicy(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createHighlightsPolicy();
        $mediaMap = $this->mediaFixtures();
        $memberIds = array_keys($mediaMap);

        $context = new MemberSelectionContext(
            $this->createDraft('highlights', $memberIds, 'highlights.sprint'),
            $policy,
            $mediaMap,
            $this->qualityScores(),
            [],
        );

        $result    = $selector->select('highlights', $memberIds, $context);
        $telemetry = $result->getTelemetry();

        self::assertSame($policy->getProfileKey(), $telemetry['policy']['profile']);
        self::assertSame('highlights.sprint', $telemetry['storyline']);
        self::assertSame('highlights.sprint', $telemetry['policy']['storyline']);
        self::assertSame(10, $telemetry['counts']['considered']);
        self::assertArrayHasKey('faces', $telemetry['metrics']);

        $rejections = $telemetry['rejections'];
        foreach ([
            'time_gap',
            'staypoint_quota',
            'phash_similarity',
            'scene_balance',
            'orientation_balance',
            'people_balance',
        ] as $key) {
            self::assertArrayHasKey($key, $rejections);
            self::assertGreaterThanOrEqual(0, $rejections[$key]);
        }

        $expectedSpacing = max(25, (int) floor($policy->getMinSpacingSeconds() * 0.6));

        self::assertGreaterThan(0, $rejections['phash_similarity']);
        self::assertArrayHasKey('relaxations', $telemetry);
        self::assertGreaterThanOrEqual(2, count($telemetry['relaxations']));
        self::assertSame($expectedSpacing, $telemetry['relaxations'][1]['policy']['min_spacing_seconds']);
        self::assertSame($expectedSpacing, $telemetry['policy']['min_spacing_seconds']);
    }

    #[Test]
    public function sequentialRelaxationsAccumulatePolicyAdjustments(): void
    {
        $selector = new PolicyDrivenMemberSelector(
            hardStages: [
                new TimeGapStage(),
                new PhashDiversityStage(),
            ],
            softStages: [],
        );

        $policy = new SelectionPolicy(
            profileKey: 'stacked',
            targetTotal: 2,
            minimumTotal: 2,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 600,
            phashMinHamming: 12,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.0,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.0,
            peripheralDayMaxTotal: null,
            peripheralDayHardCap: null,
            dayQuotas: [],
            dayContext: [],
            metadata: [],
        );

        $first  = $this->createMedia(1, '2024-05-16T10:00:00+02:00', '0000000000000000', [], [52.500, 13.400], [4000, 3000]);
        $second = $this->createMedia(2, '2024-05-16T10:06:40+02:00', '0000000000001ff0', [], [52.600, 13.500], [4000, 3000]);

        $memberIds = [1, 2];
        $mediaMap  = [1 => $first, 2 => $second];
        $quality   = [1 => 0.9, 2 => 0.8];

        $context = new MemberSelectionContext(
            $this->createDraft('stacked', $memberIds, 'stacked.storyline'),
            $policy,
            $mediaMap,
            $quality,
            [],
        );

        $result    = $selector->select('stacked', $memberIds, $context);
        $telemetry = $result->getTelemetry();

        self::assertSame([1, 2], $result->getMemberIds());
        self::assertSame(2, $telemetry['counts']['selected']);

        self::assertArrayHasKey('relaxations', $telemetry);
        self::assertCount(2, $telemetry['relaxations']);

        $firstRelaxation = $telemetry['relaxations'][0];
        self::assertSame(0, $firstRelaxation['step']);
        self::assertSame(1, $firstRelaxation['members']);
        self::assertSame(600, $firstRelaxation['policy']['min_spacing_seconds']);
        self::assertSame(12, $firstRelaxation['policy']['phash_min_hamming']);

        $secondRelaxation = $telemetry['relaxations'][1];
        self::assertSame(1, $secondRelaxation['step']);
        self::assertSame(1, $secondRelaxation['members']);
        self::assertSame(360, $secondRelaxation['policy']['min_spacing_seconds']);
        self::assertSame(12, $secondRelaxation['policy']['phash_min_hamming']);

        self::assertSame(360, $telemetry['policy']['min_spacing_seconds']);
        self::assertSame(9, $telemetry['policy']['phash_min_hamming']);
    }

    #[Test]
    public function groupFaceBonusSurvivesBurstCollapse(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();

        $groupShot = $this->createMedia(101, '2024-05-20T10:00:00+02:00', 'aaaaaaaaaaaaaaa0', [], [52.5, 13.4], [4000, 3000]);
        $groupShot->setFacesCount(4);
        $groupShot->setHasFaces(true);
        $groupShot->setFeatures([
            'faces' => ['largest_coverage' => 0.32],
        ]);
        $groupShot->setBurstUuid('burst-group');

        $single = $this->createMedia(102, '2024-05-20T10:00:01+02:00', 'bbbbbbbbbbbbbbb0', [], [52.5, 13.4], [4000, 3000]);
        $single->setFacesCount(1);
        $single->setHasFaces(true);
        $single->setFeatures([
            'faces' => ['largest_coverage' => 0.58],
        ]);
        $single->setBurstUuid('burst-group');

        $memberIds   = [101, 102];
        $mediaMap    = [101 => $groupShot, 102 => $single];
        $quality     = [101 => 0.7, 102 => 0.7];
        $draft       = $this->createDraft('vacation', $memberIds);
        $candidates  = $this->invokeBuildCandidates($selector, $memberIds, $mediaMap, $quality, $policy, $draft);

        self::assertSame(1, $candidates['drops']['burst']);
        self::assertCount(1, $candidates['eligible']);
        self::assertSame(101, $candidates['eligible'][0]['id']);
    }

    #[Test]
    public function dominantCloseUpLosesBurstCompetition(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();

        $balanced = $this->createMedia(201, '2024-05-20T12:00:00+02:00', 'ccccccccccccccc0', [], [52.6, 13.5], [4000, 3000]);
        $balanced->setFacesCount(2);
        $balanced->setHasFaces(true);
        $balanced->setFeatures([
            'faces' => ['largest_coverage' => 0.36],
        ]);
        $balanced->setBurstUuid('burst-close');

        $closeUp = $this->createMedia(202, '2024-05-20T12:00:01+02:00', 'ddddddddddddddd0', [], [52.6, 13.5], [4000, 3000]);
        $closeUp->setFacesCount(1);
        $closeUp->setHasFaces(true);
        $closeUp->setFeatures([
            'faces' => ['largest_coverage' => 0.82],
        ]);
        $closeUp->setBurstUuid('burst-close');

        $memberIds   = [201, 202];
        $mediaMap    = [201 => $balanced, 202 => $closeUp];
        $quality     = [201 => 0.7, 202 => 0.7];
        $draft       = $this->createDraft('vacation', $memberIds);
        $candidates  = $this->invokeBuildCandidates($selector, $memberIds, $mediaMap, $quality, $policy, $draft);

        self::assertSame(1, $candidates['drops']['burst']);
        self::assertCount(1, $candidates['eligible']);
        self::assertSame(201, $candidates['eligible'][0]['id']);
    }

    #[Test]
    public function buildCandidatesAnnotatesSceneBuckets(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();

        $group = $this->createMedia(301, '2024-05-18T18:00:00+02:00', 'aaaaaaaaaaaaaaa1', [], [52.4, 13.4], [4000, 3000]);
        $group->setFacesCount(4);
        $group->setHasFaces(true);
        $group->setFeatures([
            'faces' => ['largest_coverage' => 0.30],
        ]);

        $panorama = $this->createMedia(302, '2024-05-18T11:00:00+02:00', 'bbbbbbbbbbbbbbb1', [], [52.5, 13.5], [8000, 2000]);
        $panorama->setIsPanorama(true);

        $night = $this->createMedia(303, '2024-05-18T22:30:00+02:00', 'ccccccccccccccc1', [], [52.6, 13.6], [4000, 3000]);
        $night->setFeatures([
            'calendar' => ['daypart' => 'night'],
        ]);
        $night->setSceneTags([
            ['label' => 'Night city lights', 'score' => 0.92],
        ]);

        $food = $this->createMedia(304, '2024-05-18T12:30:00+02:00', 'ddddddddddddddd1', [], [52.7, 13.7], [4000, 3000]);
        $food->setSceneTags([
            ['label' => 'Delicious food spread', 'score' => 0.95],
        ]);

        $memberIds = [301, 302, 303, 304];
        $mediaMap  = [301 => $group, 302 => $panorama, 303 => $night, 304 => $food];
        $quality   = [301 => 0.8, 302 => 0.8, 303 => 0.8, 304 => 0.8];
        $draft     = $this->createDraft('vacation', $memberIds);

        $candidates = $this->invokeBuildCandidates($selector, $memberIds, $mediaMap, $quality, $policy, $draft);

        $eligible = $candidates['eligible'];
        self::assertCount(4, $eligible);

        $buckets = array_map(static fn (array $candidate): string => $candidate['bucket'], $eligible);

        self::assertContains('person_group', $buckets);
        self::assertContains('panorama', $buckets);
        self::assertContains('night', $buckets);
        self::assertContains('food', $buckets);
        self::assertGreaterThanOrEqual(4, count(array_values(array_unique($buckets))));
    }

    #[Test]
    public function sceneDiversityStageHonoursTargetShare(): void
    {
        $stage = new SceneDiversityStage();
        $policy = new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 8,
            minimumTotal: 4,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: [
                'person_group' => 0.25,
                'outdoor'      => 0.75,
            ],
        );

        $candidates = [
            ['id' => 1, 'timestamp' => 1, 'score' => 1.0, 'bucket' => 'person_group'],
            ['id' => 2, 'timestamp' => 2, 'score' => 1.0, 'bucket' => 'person_group'],
            ['id' => 3, 'timestamp' => 3, 'score' => 1.0, 'bucket' => 'outdoor'],
            ['id' => 4, 'timestamp' => 4, 'score' => 1.0, 'bucket' => 'outdoor'],
            ['id' => 5, 'timestamp' => 5, 'score' => 1.0, 'bucket' => 'outdoor'],
        ];

        $telemetry = new SelectionTelemetry();
        $result    = $stage->apply($candidates, $policy, $telemetry);

        $personGroupSelected = array_filter(
            $result,
            static fn (array $candidate): bool => $candidate['bucket'] === 'person_group'
        );

        self::assertCount(1, $personGroupSelected);

        $reasons = $telemetry->reasonCounts();
        self::assertGreaterThan(0, $reasons[SelectionTelemetry::REASON_SCENE]);
    }

    #[Test]
    public function pipelineTrimsSortedCandidatesToPolicyTarget(): void
    {
        $selector = new PolicyDrivenMemberSelector(
            hardStages: [
                new class() implements SelectionStageInterface {
                    public function getName(): string
                    {
                        return 'passthrough';
                    }

                    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
                    {
                        return $candidates;
                    }
                },
            ],
            softStages: [],
        );

        $policy = new SelectionPolicy(
            profileKey: 'trim-test',
            targetTotal: 5,
            minimumTotal: 3,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 15,
            phashMinHamming: 8,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.1,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 0,
            phashPercentile: 0.35,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.05,
        );

        $candidates = [
            ['id' => 7, 'timestamp' => 700, 'score' => 1.0],
            ['id' => 3, 'timestamp' => 300, 'score' => 0.95],
            ['id' => 1, 'timestamp' => 100, 'score' => 0.9],
            ['id' => 6, 'timestamp' => 600, 'score' => 0.85],
            ['id' => 2, 'timestamp' => 200, 'score' => 0.8],
            ['id' => 5, 'timestamp' => 500, 'score' => 0.75],
            ['id' => 4, 'timestamp' => 400, 'score' => 0.7],
        ];

        $telemetry = new SelectionTelemetry();
        $method    = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runPipeline');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $result */
        $result = $method->invoke($selector, $candidates, $policy, $telemetry);

        self::assertCount(5, $result);
        self::assertSame(100, $result[0]['timestamp']);
        self::assertSame([1, 2, 3, 6, 7], [
            $result[0]['id'],
            $result[1]['id'],
            $result[2]['id'],
            $result[3]['id'],
            $result[4]['id'],
        ]);
    }

    #[Test]
    public function higherScoringCandidateSurvivesQuotaAfterTrimmingBeforeSorting(): void
    {
        $selector = new PolicyDrivenMemberSelector(
            hardStages: [
                new class() implements SelectionStageInterface {
                    public function getName(): string
                    {
                        return 'score-ordered';
                    }

                    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
                    {
                        usort(
                            $candidates,
                            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
                        );

                        return $candidates;
                    }
                },
            ],
            softStages: [],
        );

        $policy = new SelectionPolicy(
            profileKey: 'score-quota',
            targetTotal: 2,
            minimumTotal: 1,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
        );

        $candidates = [
            ['id' => 1, 'timestamp' => 100, 'score' => 0.4],
            ['id' => 2, 'timestamp' => 150, 'score' => 0.6],
            ['id' => 3, 'timestamp' => 200, 'score' => 0.9],
            ['id' => 4, 'timestamp' => 250, 'score' => 0.2],
        ];

        $telemetry = new SelectionTelemetry();
        $method    = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runPipeline');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $result */
        $result = $method->invoke($selector, $candidates, $policy, $telemetry);

        self::assertSame(2, count($result));
        self::assertSame([150, 200], [
            $result[0]['timestamp'],
            $result[1]['timestamp'],
        ]);
        self::assertSame([2, 3], [
            $result[0]['id'],
            $result[1]['id'],
        ]);
    }

    #[Test]
    public function padsSelectionToMeetMinimumWithFallbackCandidates(): void
    {
        $selector = new PolicyDrivenMemberSelector(
            hardStages: [
                new class() implements SelectionStageInterface {
                    public function getName(): string
                    {
                        return 'limit-one';
                    }

                    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
                    {
                        if ($candidates === []) {
                            return [];
                        }

                        return [$candidates[0]];
                    }
                },
            ],
            softStages: [],
        );

        $policy = new SelectionPolicy(
            profileKey: 'pad-test',
            targetTotal: 4,
            minimumTotal: 3,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
        );

        $memberIds = [101, 102, 103, 104];
        $mediaMap  = [];
        $quality   = [];

        $timestamps = [
            101 => '2024-01-01T09:00:00+00:00',
            102 => '2024-01-01T10:00:00+00:00',
            103 => '2024-01-01T11:00:00+00:00',
            104 => '2024-01-01T12:00:00+00:00',
        ];

        $scores = [
            101 => 0.95,
            102 => 0.90,
            103 => 0.85,
            104 => 0.80,
        ];

        foreach ($memberIds as $id) {
            $media = $this->createMedia(
                $id,
                $timestamps[$id],
                'ffffffffffffffff',
                [],
                [52.5, 13.4],
                [4000, 3000],
            );
            $media->setQualityScore($scores[$id]);

            $mediaMap[$id] = $media;
            $quality[$id]  = $scores[$id];
        }

        $draft   = $this->createDraft('pad-test', $memberIds);
        $context = new MemberSelectionContext($draft, $policy, $mediaMap, $quality, []);

        $result       = $selector->select('pad-test', $memberIds, $context);
        $selectedIds  = $result->getMemberIds();
        $telemetry    = $result->getTelemetry();

        self::assertSame([101, 102, 103], $selectedIds);
        self::assertSame(3, $telemetry['counts']['selected']);
        self::assertSame(2, $telemetry['counts']['padded']);
        self::assertArrayHasKey('padding', $telemetry);
        self::assertSame(2, $telemetry['padding']['added']);
        self::assertSame(4, $telemetry['padding']['eligible_pool']);
    }

    /**
     * @return array<int, Media>
     */
    private function mediaFixtures(): array
    {
        return [
            1  => $this->createMedia(1, '2024-05-16T10:00:00+02:00', 'fffffffffffffff0', ['Alice'], [52.500, 13.400], [4000, 3000]),
            2  => $this->createMedia(2, '2024-05-16T10:20:00+02:00', 'fffffffffffffff1', ['Alice'], [52.500, 13.400], [4000, 3000]),
            3  => $this->createMedia(3, '2024-05-16T14:00:00+02:00', '0fffffffffffffff', ['Bob'], [52.500, 13.400], [3000, 4000]),
            4  => $this->createMedia(4, '2024-05-16T18:00:00+02:00', '1fffffffffffffff', ['Alice'], [52.500, 13.400], [4000, 3000]),
            5  => $this->createMedia(5, '2024-05-17T09:00:00+02:00', 'fffffffffffffff0', ['Alice'], [52.600, 13.500], [4000, 3000]),
            6  => $this->createMedia(6, '2024-05-17T12:00:00+02:00', '2fffffffffffffff', ['Alice'], [52.700, 13.600], [4000, 3000]),
            7  => $this->createMedia(7, '2024-05-17T18:00:00+02:00', '3fffffffffffffff', ['Alice'], [52.800, 13.700], [4000, 3000]),
            8  => $this->createMedia(8, '2024-05-17T21:00:00+02:00', '4fffffffffffffff', ['Alice'], [52.850, 13.750], [4000, 3000]),
            9  => $this->createMedia(9, '2024-05-18T12:00:00+02:00', '5fffffffffffffff', ['Alice'], [52.900, 13.800], [4000, 3000]),
            10 => $this->createMedia(10, '2024-05-19T12:00:00+02:00', '6fffffffffffffff', ['Alice'], [53.000, 13.900], [3000, 4000]),
        ];
    }

    /**
     * @return array<int, float>
     */
    private function qualityScores(): array
    {
        return [
            1 => 0.7,
            2 => 0.7,
            3 => 0.7,
            4 => 0.7,
            5 => 0.7,
            6 => 0.7,
            7 => 0.7,
            8 => 0.7,
            9 => 0.7,
            10 => 0.7,
        ];
    }

    private function createSelector(): PolicyDrivenMemberSelector
    {
        return new PolicyDrivenMemberSelector(
            hardStages: [
                new TimeGapStage(),
                new StaypointQuotaStage(),
                new PhashDiversityStage(),
            ],
            softStages: [
                new SceneDiversityStage(),
                new OrientationBalanceStage(),
                new PeopleBalanceStage(),
            ],
        );
    }

    private function createVacationPolicy(): SelectionPolicy
    {
        return new SelectionPolicy(
            profileKey: 'vacation',
            targetTotal: 72,
            minimumTotal: 48,
            maxPerDay: 6,
            timeSlotHours: 3.0,
            minSpacingSeconds: 1800,
            phashMinHamming: 8,
            maxPerStaypoint: 1,
            relaxedMaxPerStaypoint: 2,
            qualityFloor: 0.6,
            videoBonus: 0.28,
            faceBonus: 0.36,
            selfiePenalty: 0.22,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: 0.34,
        );
    }

    private function createHighlightsPolicy(): SelectionPolicy
    {
        return new SelectionPolicy(
            profileKey: 'highlights',
            targetTotal: 34,
            minimumTotal: 26,
            maxPerDay: 4,
            timeSlotHours: 3.0,
            minSpacingSeconds: 2100,
            phashMinHamming: 9,
            maxPerStaypoint: 2,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.6,
            videoBonus: 0.38,
            faceBonus: 0.36,
            selfiePenalty: 0.24,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: 0.32,
        );
    }

    /**
     * @param list<int> $memberIds
     */
    private function createDraft(string $algorithm, array $memberIds, ?string $storyline = null): ClusterDraft
    {
        $storyline ??= $algorithm . '.default';

        return new ClusterDraft(
            algorithm: $algorithm,
            params: [],
            centroid: ['lat' => 52.5, 'lon' => 13.4],
            members: $memberIds,
            storyline: $storyline,
        );
    }

    /**
     * @param list<int> $memberIds
     * @param array<int, Media> $mediaMap
     * @param array<int, float|null> $qualityScores
     *
     * @return array{eligible: list<array<string, mixed>>, drops: array<string, int>, all: list<int>}
     */
    private function invokeBuildCandidates(
        PolicyDrivenMemberSelector $selector,
        array $memberIds,
        array $mediaMap,
        array $qualityScores,
        SelectionPolicy $policy,
        ClusterDraft $draft,
        array $daySegments = [],
    ): array {
        $method = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'buildCandidates');
        $method->setAccessible(true);

        /** @var array{eligible: list<array<string, mixed>>, drops: array<string, int>, all: list<int>} $result */
        $result = $method->invoke($selector, $memberIds, $mediaMap, $qualityScores, $policy, $draft, $daySegments);

        return $result;
    }

    /**
     * @return list<int>
     */
    private function bitsFromHex(string $hash): array
    {
        $bits      = [];
        $normalised = strtolower($hash);
        $length    = strlen($normalised);

        for ($i = 0; $i < $length && $i < 16; ++$i) {
            $nibble = hexdec($normalised[$i]);
            for ($bit = 3; $bit >= 0; --$bit) {
                $bits[] = ($nibble >> $bit) & 1;
            }
        }

        return $bits;
    }

    private function createMedia(
        int $id,
        string $takenAt,
        string $phash,
        array $persons,
        array $coords,
        array $size,
    ): Media {
        $media = new Media('/tmp/media_' . $id . '.jpg', 'checksum-' . $id, 1000);
        $this->assignEntityId($media, $id);

        $timestamp = new DateTimeImmutable($takenAt);
        $media->setTakenAt($timestamp);
        $media->setQualityScore(0.7);
        $media->setPhash($phash);
        $media->setPhash64($phash);
        $media->setPersons($persons);
        $media->setIsVideo(false);
        $media->setNoShow(false);
        $media->setGpsLat($coords[0]);
        $media->setGpsLon($coords[1]);
        $media->setWidth($size[0]);
        $media->setHeight($size[1]);
        $media->setOrientation($size[0] === $size[1] ? 1 : ($size[0] > $size[1] ? 1 : 6));

        return $media;
    }
}
