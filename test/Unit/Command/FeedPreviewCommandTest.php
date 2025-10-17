<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MagicSunday\Memories\Command\FeedPreviewCommand;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster as ClusterEntity;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\PerMediaCapStage;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfile;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfileProvider;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;

use function preg_replace;

final class FeedPreviewCommandTest extends TestCase
{
    #[Test]
    public function itOverridesPersonalizationProfileWhenOptionsProvided(): void
    {
        $clusterEntity = new ClusterEntity(
            'travel',
            ['score' => 0.82, 'group' => 'stories'],
            ['lat' => 0.0, 'lon' => 0.0],
            [1, 2, 3, 4],
        );

        $entityManager = $this->createEntityManagerWithResult([$clusterEntity], 5);

        $mapper = new ClusterEntityToDraftMapper();

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->with(
                self::callback(static function (array $drafts): bool {
                    self::assertCount(1, $drafts);
                    self::assertInstanceOf(ClusterDraft::class, $drafts[0]);

                    return true;
                }),
                self::isCallable(),
            )
            ->willReturnArgument(0);

        $capturedProfile = null;

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->willReturnCallback(function (array $clusters, ?FeedPersonalizationProfile $profile) use (&$capturedProfile) {
                self::assertCount(1, $clusters);
                $draft = $clusters[0];
                self::assertInstanceOf(ClusterDraft::class, $draft);
                self::assertSame('travel', $draft->getAlgorithm());
                self::assertSame([1, 2, 3, 4], $draft->getMembers());
                self::assertSame(['score' => 0.82, 'group' => 'stories'], $draft->getParams());
                $capturedProfile = $profile;

                return [
                    new MemoryFeedItem('travel', 'Titel', 'Sub', null, [1, 2, 3], 0.91, ['scene_tags' => []]),
                ];
            });

        $perMediaCapStage = $this->createPerMediaCapStage();
        $profileProvider  = $this->createProfileProvider();
        $selectionPolicies = new SelectionPolicyProvider(['default' => []], 'default');

        $command = new FeedPreviewCommand(
            $entityManager,
            $feedBuilder,
            $consolidator,
            $perMediaCapStage,
            $mapper,
            $selectionPolicies,
            $profileProvider,
            10,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            '--limit-clusters' => '5',
            '--min-score'      => '0.55',
            '--min-members'    => '7',
        ], [
            'decorated' => false,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertInstanceOf(FeedPersonalizationProfile::class, $capturedProfile);
        self::assertSame('default-cli', $capturedProfile->getKey());
        self::assertSame(0.55, $capturedProfile->getMinScore());
        self::assertSame(7, $capturedProfile->getMinMembers());
    }

    #[Test]
    public function itAppliesPerMediaCapOverrideForSingleRun(): void
    {
        $clusterEntity = new ClusterEntity(
            'travel',
            ['score' => 0.75, 'group' => 'stories'],
            ['lat' => 0.0, 'lon' => 0.0],
            [1, 2, 3],
        );

        $entityManager = $this->createEntityManagerWithResult([$clusterEntity], 3);

        $mapper = new ClusterEntityToDraftMapper();

        $perMediaCapStage = $this->createPerMediaCapStage(defaultCap: 2);

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->willReturnCallback(function (array $clusters) use ($perMediaCapStage) {
                self::assertCount(1, $clusters);
                self::assertInstanceOf(ClusterDraft::class, $clusters[0]);
                self::assertSame(1, $perMediaCapStage->getPerMediaCap());

                return $clusters;
            });

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->willReturn([
                new MemoryFeedItem('travel', 'Titel', 'Sub', null, [1, 2, 3], 0.9, ['scene_tags' => []]),
            ]);

        $profileProvider   = $this->createProfileProvider();
        $selectionPolicies = new SelectionPolicyProvider(['default' => []], 'default');

        $command = new FeedPreviewCommand(
            $entityManager,
            $feedBuilder,
            $consolidator,
            $perMediaCapStage,
            $mapper,
            $selectionPolicies,
            $profileProvider,
            10,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            '--limit-clusters' => '3',
            '--per-media-cap'  => '1',
        ], [
            'decorated' => false,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(2, $perMediaCapStage->getPerMediaCap());
    }

    #[Test]
    public function itRendersStorylineCountsAndTimeRangeInTable(): void
    {
        $clusterA = new ClusterEntity(
            'travel',
            ['score' => 0.82, 'group' => 'stories'],
            ['lat' => 0.0, 'lon' => 0.0],
            [1, 2, 3, 4],
        );
        $clusterB = new ClusterEntity(
            'people',
            ['score' => 0.65, 'group' => 'stories'],
            ['lat' => 0.0, 'lon' => 0.0],
            [5, 6],
        );

        $entityManager = $this->createEntityManagerWithResult([$clusterA, $clusterB], 2);

        $mapper = new ClusterEntityToDraftMapper();

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->with(
                self::callback(static function (array $drafts): bool {
                    self::assertCount(2, $drafts);
                    self::assertInstanceOf(ClusterDraft::class, $drafts[0]);

                    return true;
                }),
                self::isCallable(),
            )
            ->willReturnArgument(0);

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->willReturnCallback(static function (array $clusters) {
                self::assertCount(2, $clusters);

                return [
                    new MemoryFeedItem(
                        'travel',
                        'Titel',
                        'Sub',
                        null,
                        [1, 2, 3, 4],
                        0.91,
                        [
                            'storyline'      => 'sunny-days',
                            'member_quality' => [
                                'summary' => [
                                    'members_persisted'   => 7,
                                    'selection_counts'    => [
                                        'raw'      => 6,
                                        'curated'  => 4,
                                    ],
                                    'selection_storyline' => 'ignored',
                                ],
                                'ordered' => [11, 22, 33, 44, 55, 66],
                            ],
                            'time_range' => [
                                'from' => '2024-05-01T00:00:00+00:00',
                                'to'   => '2024-05-05T00:00:00+00:00',
                            ],
                        ],
                    ),
                    new MemoryFeedItem(
                        'people',
                        'Titel 2',
                        'Sub 2',
                        null,
                        [5, 6],
                        0.65,
                        [
                            'member_quality' => [
                                'summary' => [
                                    'selection_counts'    => [
                                        'raw' => 5,
                                    ],
                                    'selection_storyline' => 'from-summary',
                                ],
                                'ordered' => [100, 101, 102, 103, 104],
                            ],
                            'time_range' => [
                                'from' => '2024-06-01',
                                'to'   => '2024-06-01',
                            ],
                        ],
                    ),
                ];
            });

        $perMediaCapStage = $this->createPerMediaCapStage();
        $profileProvider   = $this->createProfileProvider();
        $selectionPolicies = new SelectionPolicyProvider(['default' => []], 'default');

        $command = new FeedPreviewCommand(
            $entityManager,
            $feedBuilder,
            $consolidator,
            $perMediaCapStage,
            $mapper,
            $selectionPolicies,
            $profileProvider,
            10,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            '--limit-clusters' => '2',
        ], [
            'decorated' => false,
        ]);

        $tester->assertCommandIsSuccessful();

        $normalisedOutput = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringContainsString(
            'Algorithmus Storyline Mitglieder (roh) Mitglieder (kuratiert) Zeitraum Score',
            $normalisedOutput,
        );
        self::assertStringContainsString(
            '1 travel sunny-days 7 4 2024-05-01 → 2024-05-05 0,910',
            $normalisedOutput,
        );
        self::assertStringContainsString(
            '2 people from-summary 5 2 2024-06-01 0,650',
            $normalisedOutput,
        );
    }

    #[Test]
    public function itRendersTimeRangeFromUnixTimestamps(): void
    {
        $cluster = new ClusterEntity(
            'travel',
            ['score' => 0.5, 'group' => 'stories'],
            ['lat' => 0.0, 'lon' => 0.0],
            [1, 2],
        );

        $entityManager = $this->createEntityManagerWithResult([$cluster], 1);

        $mapper = new ClusterEntityToDraftMapper();

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->with(self::callback(static function (array $drafts): bool {
                self::assertCount(1, $drafts);

                return true;
            }), self::isCallable())
            ->willReturnArgument(0);

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->willReturn([
                new MemoryFeedItem(
                    'travel',
                    'Titel',
                    'Sub',
                    null,
                    [1, 2],
                    0.5,
                    [
                        'time_range' => [
                            'from' => 1720297569,
                            'to'   => 1720383969,
                        ],
                        'member_quality' => [
                            'summary' => [
                                'selection_counts' => [
                                    'raw' => 2,
                                ],
                            ],
                        ],
                    ],
                ),
            ]);

        $perMediaCapStage = $this->createPerMediaCapStage();
        $profileProvider   = $this->createProfileProvider();
        $selectionPolicies = new SelectionPolicyProvider(['default' => []], 'default');

        $command = new FeedPreviewCommand(
            $entityManager,
            $feedBuilder,
            $consolidator,
            $perMediaCapStage,
            $mapper,
            $selectionPolicies,
            $profileProvider,
            10,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            '--limit-clusters' => '1',
        ], [
            'decorated' => false,
        ]);

        $tester->assertCommandIsSuccessful();

        $normalisedOutput = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertStringContainsString('2024-07-06 → 2024-07-07', $normalisedOutput);
    }

    /**
     * @param list<object> $result
     */
    private function createEntityManagerWithResult(array $result, int $expectedLimit): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($result);

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'orderBy', 'setMaxResults', 'getQuery'])
            ->getMock();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('setMaxResults')
            ->with($expectedLimit)
            ->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        return $entityManager;
    }

    private function createPerMediaCapStage(int $defaultCap = 2): PerMediaCapStage
    {
        return new PerMediaCapStage(
            perMediaCap: $defaultCap,
            keepOrder: [],
            algorithmGroups: [],
            defaultAlgorithmGroup: 'default',
        );
    }

    private function createProfileProvider(): FeedPersonalizationProfileProvider
    {
        return new FeedPersonalizationProfileProvider([
            'default' => [
                'min_score'             => 0.35,
                'min_members'           => 4,
                'max_per_day'           => 6,
                'max_total'             => 30,
                'max_per_algorithm'     => 8,
                'quality_floor'         => 0.3,
                'people_coverage_min'   => 0.2,
                'recent_days'           => 30,
                'stale_days'            => 365,
                'recent_score_bonus'    => 0.05,
                'stale_score_penalty'   => 0.04,
            ],
        ]);
    }
}
