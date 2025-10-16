<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterMemberSelectionServiceInterface;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\GeoCell;
use PHPUnit\Framework\Attributes\Test;

final class ClusterPersistenceServiceTest extends TestCase
{
    #[Test]
    public function persistBatchedChunksFingerprintLookupAndSkipsExistingClusters(): void
    {
        $media = $this->buildMediaRange(12);

        $lookup = new class($media) implements MemberMediaLookupInterface {
            /** @param array<int, Media> $media */
            public function __construct(private readonly array $media)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $result = [];
                foreach ($ids as $id) {
                    if (isset($this->media[$id])) {
                        $result[] = $this->media[$id];
                    }
                }

                return $result;
            }
        };

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturn(null);

        $selectionService = new class implements ClusterMemberSelectionServiceInterface {
            public function curate(ClusterDraft $draft): ClusterDraft
            {
                return $draft;
            }
        };

        $drafts = [
            new ClusterDraft('massive', [], ['lat' => 48.1, 'lon' => 11.5], [1, 2]),
            new ClusterDraft('massive', [], ['lat' => 48.1, 'lon' => 11.5], [3, 4]),
            new ClusterDraft('massive', [], ['lat' => 48.1, 'lon' => 11.5], [5, 6]),
            new ClusterDraft('massive', [], ['lat' => 48.1, 'lon' => 11.5], [7, 8]),
            new ClusterDraft('massive', [], ['lat' => 48.1, 'lon' => 11.5], [9, 10]),
            new ClusterDraft('massive', [], ['lat' => 48.1, 'lon' => 11.5], [11, 12]),
        ];

        $fingerprints = array_map(static fn (ClusterDraft $draft): string => Cluster::computeFingerprint($draft->getMembers()), $drafts);

        $chunkedResults = [
            [['alg' => 'massive', 'fp' => $fingerprints[0]]],
            [['alg' => 'massive', 'fp' => $fingerprints[3]]],
            [],
        ];

        $capturedFpsParameters = [];

        $qbMocks = [];
        foreach ($chunkedResults as $index => $rows) {
            $query = $this->getMockBuilder(Query::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getResult'])
                ->getMock();
            $query->method('getResult')->willReturn($rows);

            $qb = $this->getMockBuilder(QueryBuilder::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['select', 'from', 'where', 'andWhere', 'setParameter', 'getQuery'])
                ->getMock();

            $qb->method('select')->willReturnSelf();
            $qb->method('from')->willReturnSelf();
            $qb->method('where')->willReturnSelf();
            $qb->method('andWhere')->willReturnSelf();
            $qb->method('setParameter')->willReturnCallback(function (string $param, mixed $value) use (&$capturedFpsParameters, $index, $qb): QueryBuilder {
                if ($param === 'fps') {
                    $capturedFpsParameters[$index] = $value;
                }

                return $qb;
            });
            $qb->method('getQuery')->willReturn($query);

            $qbMocks[] = $qb;
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(count($qbMocks)))->method('createQueryBuilder')->willReturnOnConsecutiveCalls(...$qbMocks);

        $persistedFingerprints = [];
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persistedFingerprints): void {
            self::assertInstanceOf(Cluster::class, $entity);
            $persistedFingerprints[] = Cluster::computeFingerprint($entity->getMembers());
        });
        $em->expects(self::once())->method('flush');
        $em->expects(self::once())->method('clear');

        $service = new ClusterPersistenceService(
            $em,
            $lookup,
            $selectionService,
            $coverPicker,
            defaultBatchSize: 10,
            maxMembers: 20,
            fingerprintLookupBatchSize: 2,
        );

        $persistedCount = $service->persistBatched($drafts, 10, null);

        ksort($capturedFpsParameters);
        $expectedChunks = array_chunk($fingerprints, 2);
        $capturedChunks = array_values($capturedFpsParameters);

        self::assertSame($expectedChunks, $capturedChunks);

        $expectedPersisted = [
            $fingerprints[1],
            $fingerprints[2],
            $fingerprints[4],
            $fingerprints[5],
        ];
        sort($expectedPersisted);

        $actualPersisted = $persistedFingerprints;
        sort($actualPersisted);

        self::assertSame(4, $persistedCount);
        self::assertSame($expectedPersisted, $actualPersisted);
    }

    #[Test]
    public function persistStreamingComputesMetadata(): void
    {
        $media  = $this->buildMediaSet();
        $lookup = new class($media) implements MemberMediaLookupInterface {
            /** @param array<int, Media> $media */
            public function __construct(private readonly array $media)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $result = [];
                foreach ($ids as $id) {
                    if (isset($this->media[$id])) {
                        $result[] = $this->media[$id];
                    }
                }

                return $result;
            }
        };

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturn($media[2]);

        $persisted = null;

        $em = $this->createMock(EntityManagerInterface::class);

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn([]);

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'where', 'andWhere', 'setParameter', 'getQuery'])
            ->getMock();
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });
        $em->method('flush');
        $em->method('clear');

        $selectionService = new class implements ClusterMemberSelectionServiceInterface {
            public function curate(ClusterDraft $draft): ClusterDraft
            {
                $reversed = array_reverse($draft->getMembers());
                $params   = $draft->getParams();
                $quality  = $params['member_quality'] ?? [];
                if (!is_array($quality)) {
                    $quality = [];
                }

                $quality['ordered'] = $reversed;
                $quality['summary'] = [
                    'selection_counts' => [
                        'raw'     => count($draft->getMembers()),
                        'curated' => count($reversed),
                        'dropped' => 0,
                    ],
                ];

                $params['member_quality'] = $quality;
                $params['member_selection'] = [
                    'storyline' => $draft->getStoryline(),
                    'counts'    => [
                        'raw'     => count($draft->getMembers()),
                        'curated' => count($reversed),
                        'dropped' => 0,
                    ],
                    'near_duplicates' => ['blocked' => 0, 'replacements' => 0],
                    'spacing'         => ['average_seconds' => 0.0, 'rejections' => 0],
                    'options'         => ['selector' => 'demo'],
                ];

                return $draft->withParams($params);
            }
        };

        $service = new ClusterPersistenceService(
            $em,
            $lookup,
            $selectionService,
            $coverPicker,
            defaultBatchSize: 10,
            maxMembers: 20
        );

        $draft = new ClusterDraft(
            algorithm: 'demo',
            params: [
                'algorithmVersion' => '2024.1',
                'member_quality' => ['ordered' => [2, 1, 3]],
                'movement'       => [
                    'segment_count'                               => 4,
                    'fast_segment_count'                          => 2,
                    'fast_segment_ratio'                          => 0.5,
                    'speed_sample_count'                          => 3,
                    'avg_speed_mps'                               => 12.3,
                    'max_speed_mps'                               => 18.5,
                    'heading_sample_count'                        => 2,
                    'avg_heading_change_deg'                      => 45.0,
                    'consistent_heading_segment_count'            => 2,
                    'heading_consistency_ratio'                   => 1.0,
                    'fast_segment_speed_threshold_mps'            => 5.0,
                    'min_fast_segment_count_threshold'            => 2,
                    'max_heading_change_threshold_deg'            => 90.0,
                    'min_consistent_heading_segments_threshold'   => 1,
                ],
            ],
            centroid: ['lat' => 48.123456, 'lon' => 11.654321],
            members: [1, 2, 3],
        );

        $persistedCount = $service->persistStreaming([$draft], null);

        self::assertSame(1, $persistedCount);
        self::assertNotNull($persisted);
        self::assertInstanceOf(Cluster::class, $persisted);

        self::assertInstanceOf(DateTimeImmutable::class, $persisted->getStartAt());
        self::assertInstanceOf(DateTimeImmutable::class, $persisted->getEndAt());

        $originalMembers = $draft->getMembers();
        self::assertSame($originalMembers, $persisted->getMembers());
        self::assertSame(3, $persisted->getMembersCount());
        self::assertSame(2, $persisted->getPhotoCount());
        self::assertSame(1, $persisted->getVideoCount());
        self::assertSame(2, $persisted->getCover()?->getId());
        self::assertSame($media[1]->getLocation(), $persisted->getLocation());
        self::assertSame('2024.1', $persisted->getAlgorithmVersion());
        $persistedParams = $persisted->getParams();
        $persistedSummary = $persistedParams['member_quality']['summary'] ?? [];
        self::assertIsArray($persistedSummary);
        self::assertSame(3, $persistedSummary['members_persisted']);
        self::assertSame(3, $persistedSummary['curated_overlay_count']);
        self::assertSame(3, $persistedSummary['selection_counts']['raw']);
        self::assertSame(3, $persistedSummary['selection_counts']['curated']);
        self::assertArrayHasKey('quality_avg', $persistedParams);
        self::assertArrayHasKey('quality_resolution', $persistedParams);
        self::assertArrayHasKey('people', $persistedParams);
        self::assertArrayHasKey('people_count', $persistedParams);
        self::assertArrayHasKey('movement', $persistedParams);
        self::assertSame([
            'segment_count'                               => 4,
            'fast_segment_count'                          => 2,
            'fast_segment_ratio'                          => 0.5,
            'speed_sample_count'                          => 3,
            'avg_speed_mps'                               => 12.3,
            'max_speed_mps'                               => 18.5,
            'heading_sample_count'                        => 2,
            'avg_heading_change_deg'                      => 45.0,
            'consistent_heading_segment_count'            => 2,
            'heading_consistency_ratio'                   => 1.0,
            'fast_segment_speed_threshold_mps'            => 5.0,
            'min_fast_segment_count_threshold'            => 2,
            'max_heading_change_threshold_deg'            => 90.0,
            'min_consistent_heading_segments_threshold'   => 1,
        ], $persistedParams['movement']);

        $mapper    = new ClusterEntityToDraftMapper(['demo' => 'default']);
        $roundtrip = $mapper->mapMany([$persisted]);

        self::assertCount(1, $roundtrip);

        $roundtripParams = $roundtrip[0]->getParams();
        self::assertArrayHasKey('quality_avg', $roundtripParams);
        self::assertArrayHasKey('quality_resolution', $roundtripParams);
        self::assertArrayHasKey('people', $roundtripParams);
        self::assertArrayHasKey('people_count', $roundtripParams);
        self::assertArrayHasKey('movement', $roundtripParams);
        self::assertSame([
            'segment_count'                               => 4,
            'fast_segment_count'                          => 2,
            'fast_segment_ratio'                          => 0.5,
            'speed_sample_count'                          => 3,
            'avg_speed_mps'                               => 12.3,
            'max_speed_mps'                               => 18.5,
            'heading_sample_count'                        => 2,
            'avg_heading_change_deg'                      => 45.0,
            'consistent_heading_segment_count'            => 2,
            'heading_consistency_ratio'                   => 1.0,
            'fast_segment_speed_threshold_mps'            => 5.0,
            'min_fast_segment_count_threshold'            => 2,
            'max_heading_change_threshold_deg'            => 90.0,
            'min_consistent_heading_segments_threshold'   => 1,
        ], $roundtripParams['movement']);
    }

    #[Test]
    public function persistStreamingFlushesAfterEveryDraft(): void
    {
        $media  = $this->buildMediaSet();
        $lookup = new class($media) implements MemberMediaLookupInterface {
            /** @param array<int, Media> $media */
            public function __construct(private readonly array $media)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $result = [];
                foreach ($ids as $id) {
                    if (isset($this->media[$id])) {
                        $result[] = $this->media[$id];
                    }
                }

                return $result;
            }
        };

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn([]);

        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'where', 'andWhere', 'setParameter', 'getQuery'])
            ->getMock();
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em->expects(self::atLeastOnce())->method('createQueryBuilder')->willReturn($qb);

        $persistedMembers = [];
        $em->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedMembers): void {
                self::assertInstanceOf(Cluster::class, $entity);
                $persistedMembers[] = $entity->getMembers();
            });
        $em->expects(self::exactly(2))->method('flush');
        $em->expects(self::exactly(2))->method('clear');

        $selectionService = new class implements ClusterMemberSelectionServiceInterface {
            public function curate(ClusterDraft $draft): ClusterDraft
            {
                return $draft;
            }
        };

        $service = new ClusterPersistenceService(
            $em,
            $lookup,
            $selectionService,
            $coverPicker,
            defaultBatchSize: 10,
            maxMembers: 20
        );

        $drafts = [
            new ClusterDraft('stream-a', [], ['lat' => 0.0, 'lon' => 0.0], [1, 2]),
            new ClusterDraft('stream-b', [], ['lat' => 1.0, 'lon' => 1.0], [3]),
        ];

        $callbackCount = 0;
        $persistedCount = $service->persistStreaming(
            $drafts,
            function (int $count) use (&$callbackCount): void {
                $callbackCount += $count;
            }
        );

        self::assertSame(2, $persistedCount);
        self::assertSame(2, $callbackCount);
        self::assertCount(2, $persistedMembers);
        self::assertSame([1, 2], $persistedMembers[0]);
        self::assertSame([3], $persistedMembers[1]);
    }

    #[Test]
    public function refreshExistingClusterUpdatesParamsAndMetadata(): void
    {
        $media = $this->buildMediaSet();

        $lookup = new class($media) implements MemberMediaLookupInterface {
            /** @param array<int, Media> $media */
            public function __construct(private readonly array $media)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $result = [];
                foreach ($ids as $id) {
                    if (isset($this->media[$id])) {
                        $result[] = $this->media[$id];
                    }
                }

                return $result;
            }
        };

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturn($media[1]);

        $selectionService = new class implements ClusterMemberSelectionServiceInterface {
            public function curate(ClusterDraft $draft): ClusterDraft
            {
                $params = $draft->getParams();
                $params['member_quality'] = [
                    'ordered' => [2, 1],
                    'summary' => [
                        'selection_counts' => [
                            'raw'     => count($draft->getMembers()),
                            'curated' => 2,
                        ],
                    ],
                ];

                return $draft->withParams($params);
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);

        $service = new ClusterPersistenceService(
            $em,
            $lookup,
            $selectionService,
            $coverPicker,
        );

        $cluster = new Cluster(
            'demo',
            ['storyline' => 'default'],
            ['lat' => 48.123456, 'lon' => 11.654321],
            [1, 2],
        );

        $cluster->setStartAt(new DateTimeImmutable('2024-05-20T08:00:00+00:00'));
        $cluster->setEndAt(new DateTimeImmutable('2024-05-20T09:00:00+00:00'));

        $summary = $service->refreshExistingCluster($cluster);

        self::assertSame([
            'raw_count'     => 2,
            'curated_count' => 2,
            'overlay_count' => 2,
        ], $summary);

        self::assertSame([1, 2], $cluster->getMembers());
        $params = $cluster->getParams();

        $persistedSummary = $params['member_quality']['summary'];
        self::assertSame(2, $persistedSummary['members_persisted']);
        self::assertSame(2, $persistedSummary['curated_overlay_count']);
        self::assertSame(2, $persistedSummary['selection_counts']['raw']);
        self::assertSame(2, $persistedSummary['selection_counts']['curated']);

        self::assertSame(2, $cluster->getMembersCount());
        self::assertSame(2, $cluster->getPhotoCount());
        self::assertSame(0, $cluster->getVideoCount());
        self::assertSame(1, $cluster->getCover()?->getId());
        self::assertSame($media[1]->getLocation(), $cluster->getLocation());
        self::assertNotNull($cluster->getConfigHash());
        self::assertSame(48.123456, $cluster->getCentroidLat());
        self::assertSame(11.654321, $cluster->getCentroidLon());
    }

    /**
     * @return array<int, Media>
     */
    private function buildMediaSet(): array
    {
        $home = new Location('osm', '1', 'Home', 48.1234, 11.5678, 'cell');
        $this->setId($home, 42);

        $base = new DateTimeImmutable('2024-05-20 10:00:00');

        $media1 = $this->buildMedia(1, $base);
        $media1->setIsVideo(false);
        $media1->setLocation($home);

        $media2 = $this->buildMedia(2, $base->add(new DateInterval('PT2H')));
        $media2->setIsVideo(false);
        $media2->setLocation($home);

        $media3 = $this->buildMedia(3, $base->add(new DateInterval('PT4H')));
        $media3->setIsVideo(true);

        return [
            1 => $media1,
            2 => $media2,
            3 => $media3,
        ];
    }

    /**
     * @return array<int, Media>
     */
    private function buildMediaRange(int $count): array
    {
        $base  = new DateTimeImmutable('2024-05-20 10:00:00');
        $media = [];

        for ($i = 1; $i <= $count; ++$i) {
            $media[$i] = $this->buildMedia($i, $base->add(new DateInterval('PT' . ($i * 5) . 'M')));
        }

        return $media;
    }

    private function buildMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media('path-' . $id . '.jpg', 'checksum-' . $id, 4096);
        $this->setId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setWidth(4000);
        $media->setHeight(3000);
        $media->setThumbnails(['small' => 'thumb-' . $id . '.jpg']);

        return $media;
    }

    private function setId(object $entity, int $id): void
    {
        $this->assignEntityId($entity, $id);
    }
}
