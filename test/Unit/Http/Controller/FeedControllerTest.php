<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Http\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Http\Controller\FeedController;
use MagicSunday\Memories\Http\Request;
use MagicSunday\Memories\Http\Response\BinaryFileResponse;
use MagicSunday\Memories\Http\Response\JsonResponse;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfileProvider;
use MagicSunday\Memories\Service\Feed\FeedUserPreferenceStorage;
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Service\Feed\StoryboardTextGenerator;
use MagicSunday\Memories\Service\Metadata\Exif\DefaultExifValueAccessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\DateTimeExifMetadataProcessor;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoManagerInterface;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoStatus;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function file_put_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * @internal
 *
 * @covers \MagicSunday\Memories\Http\Controller\FeedController
 */
final class FeedControllerTest extends TestCase
{
    public function testFeedAppliesScoreFilterAndBuildsMeta(): void
    {
        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $mapper = new ClusterEntityToDraftMapper([]);

        $items = [
            new MemoryFeedItem(
                algorithm: 'holiday_event',
                title: 'Winter in Berlin',
                subtitle: 'Lichterzauber an der Spree',
                coverMediaId: 1,
                memberIds: [1, 2, 3],
                score: 0.68,
                params: [
                    'group'      => 'city_and_events',
                    'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_800],
                ],
            ),
            new MemoryFeedItem(
                algorithm: 'hike_adventure',
                title: 'Alpenüberquerung',
                subtitle: 'Sonnenaufgang am Gipfel',
                coverMediaId: 4,
                memberIds: [4, 5, 6],
                score: 0.32,
                params: [
                    'group'      => 'nature_and_seasons',
                    'time_range' => ['from' => 1_600_000_000, 'to' => 1_600_000_600],
                ],
            ),
        ];

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->with([])
            ->willReturn($items);

        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('ensureForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $mediaOne   = new Media('/media/1.jpg', 'checksum-1', 100);
        $mediaTwo   = new Media('/media/2.jpg', 'checksum-2', 110);
        $mediaThree = new Media('/media/3.jpg', 'checksum-3', 120);

        $idProperty = new ReflectionProperty(Media::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($mediaOne, 1);
        $idProperty->setValue($mediaTwo, 2);
        $idProperty->setValue($mediaThree, 3);

        $mediaOneTakenAt   = new DateTimeImmutable('2024-01-01T10:00:00+00:00');
        $mediaTwoTakenAt   = new DateTimeImmutable('2024-01-02T11:15:00+00:00');
        $mediaThreeTakenAt = new DateTimeImmutable('2024-01-03T12:30:00+00:00');

        $mediaOne->setTakenAt($mediaOneTakenAt);
        $mediaOne->setCapturedLocal($mediaOneTakenAt);
        $mediaOne->setTimeSource(TimeSource::EXIF);
        $mediaOne->setTzId('UTC');

        $mediaTwo->setTakenAt($mediaTwoTakenAt);
        $mediaTwo->setCapturedLocal($mediaTwoTakenAt);
        $mediaTwo->setTimeSource(TimeSource::EXIF);
        $mediaTwo->setTzId('UTC');

        $mediaThree->setTakenAt($mediaThreeTakenAt);
        $mediaThree->setCapturedLocal($mediaThreeTakenAt);
        $mediaThree->setTimeSource(TimeSource::EXIF);
        $mediaThree->setTzId('UTC');

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([1, 2, 3], false)
            ->willReturn([$mediaOne, $mediaTwo, $mediaThree]);

        [$controller, $storagePath] = $this->createControllerWithDependencies(
            $feedBuilder,
            $clusterRepo,
            $mapper,
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
        );

        $request  = Request::create('/api/feed', 'GET', ['score' => '0.5']);
        $response = $controller->feed($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('items', $payload);
        self::assertCount(1, $payload['items']);
        self::assertSame('holiday_event', $payload['items'][0]['algorithmus']);
        self::assertSame('2024-01-01T10:00:00+00:00', $payload['items'][0]['coverAufgenommenAm']);

        $gallery = $payload['items'][0]['galerie'];
        self::assertSame('2024-01-01T10:00:00+00:00', $gallery[0]['aufgenommenAm']);
        self::assertSame('2024-01-02T11:15:00+00:00', $gallery[1]['aufgenommenAm']);
        self::assertSame('2024-01-03T12:30:00+00:00', $gallery[2]['aufgenommenAm']);

        self::assertArrayHasKey('meta', $payload);
        $meta = $payload['meta'];
        self::assertSame(1, $meta['gesamtVerfuegbar']);
        self::assertSame(1, $meta['anzahlGeliefert']);
        self::assertEqualsCanonicalizing(['holiday_event'], $meta['verfuegbareStrategien']);
        self::assertArrayHasKey('pagination', $meta);
        self::assertFalse($meta['pagination']['hatWeitere']);
        self::assertNull($meta['pagination']['nextCursor']);

        $storyboard = $payload['items'][0]['storyboard'];
        self::assertIsArray($storyboard);
        self::assertArrayHasKey('titel', $storyboard);
        self::assertArrayHasKey('beschreibung', $storyboard);
        self::assertNotSame('', $storyboard['titel']);
        self::assertNotSame('', $storyboard['beschreibung']);

        unlink($storagePath);
    }

    public function testFeedFormatsTakenAtWithExifOffset(): void
    {
        $accessor  = new DefaultExifValueAccessor();
        $processor = new DateTimeExifMetadataProcessor($accessor);
        $exif      = [
            'EXIF' => [
                'DateTimeOriginal'   => '2024:05:01 14:20:30',
                'OffsetTimeOriginal' => '+0130',
            ],
        ];

        $media = new Media('/media/offset.jpg', 'checksum-offset', 512);
        $processor->process($exif, $media);

        $reflection = new ReflectionProperty(Media::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($media, 42);

        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->with([])
            ->willReturn([
                new MemoryFeedItem(
                    algorithm: 'exif_offset',
                    title: 'Offset aufgenommen',
                    subtitle: 'Lokale Zeit gebunden',
                    coverMediaId: 42,
                    memberIds: [42],
                    score: 1.0,
                    params: [
                        'group'      => 'metadata',
                        'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_100],
                    ],
                ),
            ]);

        $mediaRepo = $this->createMock(MediaRepository::class);
        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([42], false)
            ->willReturn([$media]);

        $thumbnailResolver = new ThumbnailPathResolver();
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('ensureForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        [$controller, $storagePath] = $this->createControllerWithDependencies(
            $feedBuilder,
            $clusterRepo,
            new ClusterEntityToDraftMapper([]),
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
        );

        $request  = Request::create('/api/feed', 'GET');
        $response = $controller->feed($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('items', $payload);
        self::assertCount(1, $payload['items']);

        $item = $payload['items'][0];
        self::assertSame('2024-05-01T14:20:30+01:30', $item['coverAufgenommenAm']);
        self::assertSame('2024-05-01T14:20:30+01:30', $item['galerie'][0]['aufgenommenAm']);

        self::assertSame(90, $media->getTimezoneOffsetMin());

        unlink($storagePath);
    }

    public function testFeedRejectsInvalidDate(): void
    {
        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::never())->method('findLatest');

        $feedBuilder       = $this->createMock(FeedBuilderInterface::class);
        $mapper            = new ClusterEntityToDraftMapper([]);
        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('ensureForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        [$controller, $storagePath] = $this->createControllerWithDependencies(
            $feedBuilder,
            $clusterRepo,
            $mapper,
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
        );

        $request  = Request::create('/api/feed', 'GET', ['datum' => '2024-99-01']);
        $response = $controller->feed($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid date filter format, expected YYYY-MM-DD.', $body['error']);

        unlink($storagePath);
    }

    public function testThumbnailDeliversBinaryResponse(): void
    {
        $feedBuilder       = $this->createMock(FeedBuilderInterface::class);
        $clusterRepo       = $this->createMock(ClusterRepository::class);
        $mapper            = new ClusterEntityToDraftMapper([]);
        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('ensureForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $tempFile = tempnam(sys_get_temp_dir(), 'thumb');
        self::assertIsString($tempFile);
        file_put_contents($tempFile, 'fake');

        $media = new Media($tempFile, 'checksum', 4);
        $media->setThumbnails([320 => $tempFile]);

        $reflection = new ReflectionProperty(Media::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($media, 99);

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([99], false)
            ->willReturn([$media]);

        $thumbnailService->expects(self::never())->method('generateAll');
        $entityManager->expects(self::never())->method('flush');

        [$controller, $storagePath] = $this->createControllerWithDependencies(
            $feedBuilder,
            $clusterRepo,
            $mapper,
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
        );

        $request  = Request::create('/api/media/99/thumbnail');
        $response = $controller->thumbnail($request, 99);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($tempFile, $response->getFilePath());

        unlink($tempFile);
        unlink($storagePath);
    }

    public function testThumbnailReturnsNotFoundWhenMediaMissing(): void
    {
        $feedBuilder       = $this->createMock(FeedBuilderInterface::class);
        $clusterRepo       = $this->createMock(ClusterRepository::class);
        $mapper            = new ClusterEntityToDraftMapper([]);
        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('ensureForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([123], false)
            ->willReturn([]);

        [$controller, $storagePath] = $this->createControllerWithDependencies(
            $feedBuilder,
            $clusterRepo,
            $mapper,
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
        );

        $request  = Request::create('/api/media/123/thumbnail');
        $response = $controller->thumbnail($request, 123);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Media not found.', $body['error']);

        unlink($storagePath);
    }

    public function testThumbnailGeneratesMissingThumbnailWhenAbsent(): void
    {
        $feedBuilder       = $this->createMock(FeedBuilderInterface::class);
        $clusterRepo       = $this->createMock(ClusterRepository::class);
        $mapper            = new ClusterEntityToDraftMapper([]);
        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('ensureForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $original  = tempnam(sys_get_temp_dir(), 'orig');
        $generated = tempnam(sys_get_temp_dir(), 'gen');
        self::assertIsString($original);
        self::assertIsString($generated);
        file_put_contents($original, 'original');
        file_put_contents($generated, 'generated');

        $media      = new Media($original, 'checksum-123', 10);
        $reflection = new ReflectionProperty(Media::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($media, 12);

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([12], false)
            ->willReturn([$media]);

        $thumbnailService->expects(self::once())
            ->method('generateAll')
            ->with($original, $media)
            ->willReturn([320 => $generated]);

        $entityManager->expects(self::once())
            ->method('flush');

        [$controller, $storagePath] = $this->createControllerWithDependencies(
            $feedBuilder,
            $clusterRepo,
            $mapper,
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
        );

        $request  = Request::create('/api/media/12/thumbnail', 'GET', ['breite' => '320']);
        $response = $controller->thumbnail($request, 12);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($generated, $response->getFilePath());

        unlink($original);
        unlink($generated);
        unlink($storagePath);
    }

    /**
     * @return array{0: FeedController, 1: string}
     */
    private function createControllerWithDependencies(
        FeedBuilderInterface $feedBuilder,
        ClusterRepository $clusterRepo,
        ClusterEntityToDraftMapper $mapper,
        ThumbnailPathResolver $thumbnailResolver,
        MediaRepository $mediaRepo,
        ThumbnailServiceInterface $thumbnailService,
        SlideshowVideoManagerInterface $slideshowManager,
        EntityManagerInterface $entityManager,
    ): array {
        $profileProvider = new FeedPersonalizationProfileProvider([
            'default' => [
                'min_score'             => 0.0,
                'min_members'           => 1,
                'max_per_day'           => 24,
                'max_total'             => 120,
                'max_per_algorithm'     => 24,
                'quality_floor'         => 0.0,
                'people_coverage_min'   => 0.0,
                'recent_days'           => 0,
                'stale_days'            => 0,
                'recent_score_bonus'    => 0.0,
                'stale_score_penalty'   => 0.0,
            ],
        ]);

        $storagePath = tempnam(sys_get_temp_dir(), 'prefs');
        self::assertIsString($storagePath);

        $preferenceStorage = new FeedUserPreferenceStorage($storagePath);
        $storyboardGenerator = new StoryboardTextGenerator();

        $controller = new FeedController(
            $feedBuilder,
            $clusterRepo,
            $mapper,
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
            $profileProvider,
            $preferenceStorage,
            $storyboardGenerator,
        );

        return [$controller, $storagePath];
    }
}
