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
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberCurationStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\ClusterMemberSelectorInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionResult;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MemberCurationStageTest extends TestCase
{
    #[Test]
    public function forwardsPolicyTelemetryToMonitoringEmitter(): void
    {
        $mediaLookup = $this->createStub(MemberMediaLookupInterface::class);
        $mediaLookup->method('findByIds')->willReturn([]);

        $policyProvider = new SelectionPolicyProvider(
            profiles: [
                'default' => [
                    'target_total' => 4,
                    'minimum_total' => 2,
                    'max_per_day' => null,
                    'time_slot_hours' => null,
                    'min_spacing_seconds' => 30,
                    'phash_min_hamming' => 10,
                    'max_per_staypoint' => null,
                    'max_per_staypoint_relaxed' => null,
                    'quality_floor' => 0.0,
                    'video_bonus' => 0.0,
                    'face_bonus' => 0.0,
                    'selfie_penalty' => 0.0,
                    'max_per_year' => null,
                    'max_per_bucket' => null,
                    'video_heavy_bonus' => null,
                ],
            ],
            algorithmProfiles: ['policy-algo' => 'default'],
            defaultProfile: 'default',
        );

        $selector = $this->createMock(ClusterMemberSelectorInterface::class);
        $selector->method('select')->willReturn(
            new MemberSelectionResult(
                [5, 6],
                [
                    'counts' => [
                        'considered' => 3,
                        'eligible' => 3,
                        'selected' => 2,
                    ],
                    'rejections' => [
                        'time_gap' => 2,
                        'phash_similarity' => 1,
                        'staypoint_quota' => 0,
                        'orientation_balance' => 0,
                        'scene_balance' => 0,
                        'people_balance' => 0,
                        'no_show' => 1,
                        'quality' => 0,
                        'burst' => 1,
                    ],
                    'metrics' => [
                        'time_gaps' => [30, 60],
                        'phash_distances' => [8],
                    ],
                    'distribution' => [
                        'per_day' => ['2024-06-01' => 2],
                        'per_year' => [2024 => 2],
                        'per_bucket' => ['06-01' => 2],
                    ],
                    'policy' => ['profile' => 'default'],
                ],
            ),
        );

        $emitter = new class() implements JobMonitoringEmitterInterface {
            /**
             * @var list<array{
             *     job: string,
             *     status: string,
             *     context: array<string, mixed>
             * }>
             */
            public array $events = [];

            public function emit(string $job, string $status, array $context = []): void
            {
                $this->events[] = [
                    'job' => $job,
                    'status' => $status,
                    'context' => $context,
                ];
            }
        };

        $stage = new MemberCurationStage($mediaLookup, $policyProvider, $selector, $emitter);

        $draft = new ClusterDraft('policy-algo', [], ['lat' => 0.0, 'lon' => 0.0], [1, 2, 3]);
        $result = $stage->process([$draft]);

        self::assertCount(1, $result);
        $curated = $result[0];
        $params = $curated->getParams();
        self::assertArrayHasKey('member_selection', $params);

        $selection = $params['member_selection'];
        self::assertSame(45.0, $selection['avg_time_gap_s']);
        self::assertSame(8.0, $selection['avg_phash_distance']);
        self::assertSame([
            '2024-06-01' => 2,
        ], $selection['per_day_distribution']);
        self::assertSame(2, $selection['rejection_counts']['time_gap']);
        self::assertSame(1, $selection['rejection_counts']['phash_similarity']);

        $completedEvents = array_values(array_filter(
            $emitter->events,
            static fn (array $event): bool => $event['status'] === 'selection_completed',
        ));

        self::assertCount(1, $completedEvents);
        $payload = $completedEvents[0]['context'];
        self::assertSame(3, $payload['pre_count']);
        self::assertSame(2, $payload['post_count']);
        self::assertSame(1, $payload['dropped_near_duplicates']);
        self::assertSame(2, $payload['dropped_spacing']);
        self::assertSame(45.0, $payload['avg_time_gap_s']);
        self::assertSame(8.0, $payload['avg_phash_distance']);
    }
}
