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
    public function persistBatchedComputesMetadata(): void
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

        $service = new ClusterPersistenceService(
            $em,
            $lookup,
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

        $persistedCount = $service->persistBatched([$draft], 5, null);

        self::assertSame(1, $persistedCount);
        self::assertNotNull($persisted);
        self::assertInstanceOf(Cluster::class, $persisted);

        self::assertEquals($draft->getStartAt(), $persisted->getStartAt());
        self::assertEquals($draft->getEndAt(), $persisted->getEndAt());
        self::assertSame(3, $persisted->getMembersCount());
        self::assertSame(2, $persisted->getPhotoCount());
        self::assertSame(1, $persisted->getVideoCount());
        self::assertSame(2, $persisted->getCover()?->getId());
        self::assertSame($media[1]->getLocation(), $persisted->getLocation());
        self::assertSame('2024.1', $persisted->getAlgorithmVersion());
        self::assertSame($draft->getConfigHash(), $persisted->getConfigHash());
        self::assertSame($draft->getCentroidLat(), $persisted->getCentroidLat());
        self::assertSame($draft->getCentroidLon(), $persisted->getCentroidLon());
        self::assertSame($draft->getCentroidCell7(), $persisted->getCentroidCell7());
        self::assertSame(2, $draft->getCoverMediaId());
        self::assertSame(3, $draft->getMembersCount());
        self::assertSame(2, $draft->getPhotoCount());
        self::assertSame(1, $draft->getVideoCount());
        self::assertNotNull($draft->getConfigHash());
        self::assertSame(GeoCell::fromPoint(48.123456, 11.654321, 7), $draft->getCentroidCell7());

        $params = $draft->getParams();
        self::assertArrayHasKey('quality_avg', $params);
        self::assertGreaterThan(0.0, $params['quality_avg']);
        self::assertArrayHasKey('quality_resolution', $params);
        self::assertGreaterThan(0.0, $params['quality_resolution']);
        self::assertArrayHasKey('people', $params);
        self::assertSame(0.0, $params['people']);
        self::assertArrayHasKey('people_count', $params);
        self::assertSame(0, $params['people_count']);
        self::assertArrayHasKey('people_unique', $params);
        self::assertSame(0, $params['people_unique']);
        self::assertArrayHasKey('people_coverage', $params);
        self::assertSame(0.0, $params['people_coverage']);
        self::assertArrayHasKey('people_face_coverage', $params);
        self::assertSame(0.0, $params['people_face_coverage']);
        self::assertArrayHasKey('movement', $params);
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
        ], $params['movement']);

        $persistedParams = $persisted->getParams();
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
