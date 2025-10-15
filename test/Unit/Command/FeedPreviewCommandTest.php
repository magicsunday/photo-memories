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

final class FeedPreviewCommandTest extends TestCase
{
    #[Test]
    public function itOverridesPersonalizationProfileWhenOptionsProvided(): void
    {
        $entityManager = $this->createEntityManagerWithResult([
            $this->createMock(ClusterEntity::class),
        ], 5);

        $draft = new ClusterDraft(
            algorithm: 'travel',
            params: ['score' => 0.82, 'group' => 'stories'],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3, 4],
        );

        $mapper = $this->createMock(ClusterEntityToDraftMapper::class);
        $mapper->expects(self::once())
            ->method('mapMany')
            ->willReturn([$draft]);

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->with([$draft], self::isType('callable'))
            ->willReturn([$draft]);

        $capturedProfile = null;

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->willReturnCallback(function (array $clusters, ?FeedPersonalizationProfile $profile) use ($draft, &$capturedProfile) {
                self::assertSame([$draft], $clusters);
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
        $entityManager = $this->createEntityManagerWithResult([
            $this->createMock(ClusterEntity::class),
        ], 3);

        $draft = new ClusterDraft(
            algorithm: 'travel',
            params: ['score' => 0.75, 'group' => 'stories'],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $mapper = $this->createMock(ClusterEntityToDraftMapper::class);
        $mapper->expects(self::once())
            ->method('mapMany')
            ->willReturn([$draft]);

        $perMediaCapStage = $this->createPerMediaCapStage(defaultCap: 2);

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->willReturnCallback(function (array $clusters) use ($draft, $perMediaCapStage) {
                self::assertSame([$draft], $clusters);
                self::assertSame(1, $perMediaCapStage->getPerMediaCap());

                return [$draft];
            });

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->with([$draft], null)
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
