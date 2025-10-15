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
use MagicSunday\Memories\Service\Feed\AlgorithmLabelProvider;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfileProvider;
use MagicSunday\Memories\Service\Feed\FeedUserPreferenceStorage;
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Service\Feed\NotificationPlanner;
use MagicSunday\Memories\Service\Feed\StoryboardTextGenerator;
use MagicSunday\Memories\Service\Metadata\Exif\DefaultExifValueAccessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\DateTimeExifMetadataProcessor;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoManagerInterface;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoStatus;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use MagicSunday\Memories\Test\Support\EntityIdAssignmentTrait;
use PHPUnit\Framework\TestCase;

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
    use EntityIdAssignmentTrait;

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
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $mediaOne   = new Media('/media/1.jpg', 'checksum-1', 100);
        $mediaTwo   = new Media('/media/2.jpg', 'checksum-2', 110);
        $mediaThree = new Media('/media/3.jpg', 'checksum-3', 120);

        $this->assignEntityId($mediaOne, 1);
        $this->assignEntityId($mediaTwo, 2);
        $this->assignEntityId($mediaThree, 3);

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
        self::assertSame('http://localhost/api/media/1/thumbnail?breite=320', $gallery[0]['thumbnail']);
        self::assertSame('http://localhost/api/media/1/thumbnail?breite=1024', $gallery[0]['lightbox']);
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

        self::assertArrayHasKey('benachrichtigungen', $payload['items'][0]);

        unlink($storagePath);
    }

    public function testFeedSwapsDimensionsForRotatedMedia(): void
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
                title: 'Rotierte Erinnerung',
                subtitle: 'Testmotiv',
                coverMediaId: 5,
                memberIds: [5],
                score: 0.9,
                params: [
                    'group'      => 'city_and_events',
                    'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_600],
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
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $media = $this->createMedia(5, '/media/5.jpg', '2024-02-01T09:30:00+00:00');
        $media->setWidth(4032);
        $media->setHeight(3024);
        $media->setOrientation(6);
        $media->setNeedsRotation(false);

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([5], false)
            ->willReturn([$media]);

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

        $coverDimensions = $payload['items'][0]['coverAbmessungen'];
        self::assertSame(3024, $coverDimensions['breite']);
        self::assertSame(4032, $coverDimensions['hoehe']);
        self::assertSame(0.75, $coverDimensions['seitenverhaeltnis']);
        self::assertSame('hochformat', $coverDimensions['ausrichtung']);

        $galleryDimensions = $payload['items'][0]['galerie'][0]['abmessungen'];
        self::assertSame(3024, $galleryDimensions['breite']);
        self::assertSame(4032, $galleryDimensions['hoehe']);
        self::assertSame(0.75, $galleryDimensions['seitenverhaeltnis']);
        self::assertSame('hochformat', $galleryDimensions['ausrichtung']);

        unlink($storagePath);
    }

    public function testFeedAppliesCursorForLazyLoading(): void
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
                subtitle: 'Lichterfest an der Spree',
                coverMediaId: 10,
                memberIds: [10],
                score: 0.82,
                params: [
                    'group'      => 'city_and_events',
                    'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_800],
                ],
            ),
            new MemoryFeedItem(
                algorithm: 'holiday_event',
                title: 'Silvesterfeuerwerk',
                subtitle: 'Countdown am Brandenburger Tor',
                coverMediaId: 11,
                memberIds: [11],
                score: 0.74,
                params: [
                    'group'      => 'city_and_events',
                    'time_range' => ['from' => 1_600_000_000, 'to' => 1_600_000_500],
                ],
            ),
            new MemoryFeedItem(
                algorithm: 'holiday_event',
                title: 'Winterspaziergang',
                subtitle: 'Frostige Elbe',
                coverMediaId: 12,
                memberIds: [12],
                score: 0.63,
                params: [
                    'group'      => 'nature_and_seasons',
                    'time_range' => ['from' => 1_500_000_000, 'to' => 1_500_000_400],
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

        $mediaMap = [
            10 => $this->createMedia(10, '/media/10.jpg', '2024-01-01T09:00:00+00:00'),
            11 => $this->createMedia(11, '/media/11.jpg', '2023-12-31T23:30:00+00:00'),
            12 => $this->createMedia(12, '/media/12.jpg', '2023-12-15T15:45:00+00:00'),
        ];

        $mediaRepo->method('findByIds')
            ->willReturnCallback(
                static function (array $ids, bool $onlyVideos = false) use ($mediaMap): array {
                    $result = [];
                    foreach ($ids as $id) {
                        if (isset($mediaMap[$id])) {
                            $result[] = $mediaMap[$id];
                        }
                    }

                    return $result;
                }
            );

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
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

        $request  = Request::create('/api/feed', 'GET', ['cursor' => 'time:1600000000']);
        $response = $controller->feed($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('items', $payload);
        self::assertCount(1, $payload['items']);
        self::assertSame('Winterspaziergang', $payload['items'][0]['titel']);

        $meta = $payload['meta'];
        self::assertSame('time:1600000000', $meta['pagination']['cursor']);
        self::assertSame('time:1500000000', $meta['pagination']['nextCursor']);
        self::assertSame('time:1600000000', $meta['filter']['cursor']);

        unlink($storagePath);
    }

    public function testSpaBootstrapBuildsComponents(): void
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
                title: 'Jahreswechsel',
                subtitle: 'Feuerwerk an der Elbe',
                coverMediaId: 21,
                memberIds: [21, 22],
                score: 0.88,
                params: [
                    'group'      => 'city_and_events',
                    'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_600],
                ],
            ),
            new MemoryFeedItem(
                algorithm: 'nightlife_event',
                title: 'Lange Nacht',
                subtitle: 'Jazzclub in Hamburg',
                coverMediaId: 23,
                memberIds: [23],
                score: 0.67,
                params: [
                    'group'      => 'nightlife',
                    'time_range' => ['from' => 1_650_000_000, 'to' => 1_650_000_400],
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

        $mediaMap = [
            21 => $this->createMedia(21, '/media/21.jpg', '2023-12-31T22:30:00+00:00'),
            22 => $this->createMedia(22, '/media/22.jpg', '2023-12-31T22:45:00+00:00'),
            23 => $this->createMedia(23, '/media/23.jpg', '2023-11-11T23:15:00+00:00'),
        ];

        $mediaRepo->method('findByIds')
            ->willReturnCallback(
                static function (array $ids, bool $onlyVideos = false) use ($mediaMap): array {
                    $result = [];
                    foreach ($ids as $id) {
                        if (isset($mediaMap[$id])) {
                            $result[] = $mediaMap[$id];
                        }
                    }

                    return $result;
                }
            );

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $spaGestures = [
            'feed'         => ['refresh' => 'pull-down'],
            'timeline'     => ['open' => 'tap'],
            'story_viewer' => ['next' => 'swipe-left'],
        ];

        $spaOffline = [
            'service_worker' => '/app/sw.js',
            'scope'          => '/',
            'precache'       => ['/api/feed', '/api/feed/spa'],
            'runtime'        => [
                ['pattern' => '^/api/media/', 'strategy' => 'stale-while-revalidate'],
            ],
            'fallback'       => '/offline',
        ];

        $spaAnimations = [
            'feed'         => ['card_ms' => 220],
            'timeline'     => ['focus_ms' => 210],
            'story_viewer' => ['overlay_ms' => 180],
        ];

        [$controller, $storagePath] = $this->createControllerWithDependencies(
            $feedBuilder,
            $clusterRepo,
            $mapper,
            $thumbnailResolver,
            $mediaRepo,
            $thumbnailService,
            $slideshowManager,
            $entityManager,
            $spaGestures,
            $spaOffline,
            $spaAnimations,
            6,
        );

        $request  = Request::create('/api/feed/spa', 'GET');
        $response = $controller->spaBootstrap($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('components', $payload);
        $components = $payload['components'];

        self::assertArrayHasKey('fuerDich', $components);
        self::assertCount(2, $components['fuerDich']['items']);
        self::assertSame($spaAnimations['feed'], $components['fuerDich']['animationen']);

        self::assertArrayHasKey('timeline', $components);
        self::assertNotEmpty($components['timeline']['gruppen']);
        self::assertSame($spaGestures['timeline'], $components['timeline']['gesten']);

        self::assertArrayHasKey('storyViewer', $components);
        self::assertNotEmpty($components['storyViewer']['stories']);
        self::assertSame(3500, $components['storyViewer']['animationen']['bildMs']);
        self::assertSame(180, $components['storyViewer']['animationen']['overlay_ms']);

        self::assertArrayHasKey('offline', $components);
        self::assertSame('/app/sw.js', $components['offline']['serviceWorker']['pfad']);
        self::assertSame($spaOffline['precache'], $components['offline']['serviceWorker']['precache']);
        self::assertSame($spaGestures, $components['offline']['gesten']);

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

        $this->assignEntityId($media, 42);

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
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
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
        self::assertArrayHasKey('coverAltText', $item);
        self::assertStringContainsString('Foto', $item['coverAltText']);
        self::assertArrayHasKey('altText', $item['galerie'][0]);
        self::assertNotSame('', trim($item['galerie'][0]['altText']));

        self::assertSame(90, $media->getTimezoneOffsetMin());

        unlink($storagePath);
    }

    public function testFeedSupportsFieldSelection(): void
    {
        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $items = [
            new MemoryFeedItem(
                algorithm: 'time_similarity',
                title: 'Frühlingsmomente',
                subtitle: 'Spaziergang im Park',
                coverMediaId: 7,
                memberIds: [7, 8],
                score: 0.72,
                params: [
                    'group'      => 'nature',
                    'time_range' => ['from' => 1_710_000_000, 'to' => 1_710_086_400],
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

        $mediaSeven = $this->createMedia(7, '/media/7.jpg', '2024-04-01T10:00:00+00:00');
        $mediaSeven->setPersons(['Alex']);
        $mediaSeven->setKeywords(['Frühling']);
        $mediaSeven->setSceneTags([
            ['label' => 'Park'],
        ]);

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([7, 8], false)
            ->willReturn([$mediaSeven]);

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->expects(self::never())->method('getStatusForItem');
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

        $request  = Request::create('/api/feed', 'GET', ['felder' => 'basis,zeit', 'metaFelder' => 'basis']);
        $response = $controller->feed($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['items']);
        $item = $payload['items'][0];

        self::assertArrayHasKey('coverAltText', $item);
        self::assertArrayHasKey('zeitspanne', $item);
        self::assertArrayNotHasKey('galerie', $item);
        self::assertArrayNotHasKey('kontext', $item);
        self::assertArrayNotHasKey('zusatzdaten', $item);
        self::assertArrayNotHasKey('slideshow', $item);
        self::assertArrayNotHasKey('storyboard', $item);
        self::assertArrayNotHasKey('benachrichtigungen', $item);

        self::assertArrayHasKey('meta', $payload);
        self::assertArrayHasKey('erstelltAm', $payload['meta']);
        self::assertArrayNotHasKey('pagination', $payload['meta']);
        self::assertArrayNotHasKey('filter', $payload['meta']);
        self::assertArrayNotHasKey('personalisierung', $payload['meta']);

        unlink($storagePath);
    }

    public function testFeedItemReturnsCompleteGallery(): void
    {
        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $members = range(1, 10);
        $item     = new MemoryFeedItem(
            algorithm: 'holiday_event',
            title: 'Winter in Berlin',
            subtitle: 'Lichterzauber an der Spree',
            coverMediaId: 1,
            memberIds: $members,
            score: 0.68,
            params: [
                'group'      => 'city_and_events',
                'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_800],
            ],
        );

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->with([])
            ->willReturn([$item]);

        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);

        $mediaRepo->expects(self::exactly(2))
            ->method('findByIds')
            ->willReturnCallback(function (array $ids, bool $onlyVideos = false): array {
                self::assertFalse($onlyVideos);

                return array_map(
                    fn (int $id): Media => $this->createMedia($id, '/media/' . $id . '.jpg', '2024-04-01T10:00:00+00:00'),
                    $ids,
                );
            });

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
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

        $itemId   = hash('sha1', 'holiday_event|' . implode(',', $members));
        $request  = Request::create('/api/feed/' . $itemId, 'GET');
        $response = $controller->feedItem($request, $itemId);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('item', $payload);
        self::assertArrayHasKey('galerie', $payload['item']);
        self::assertCount(count($members), $payload['item']['galerie']);
        self::assertSame($members, $payload['item']['mitglieder']);
        self::assertSame(
            end($members),
            $payload['item']['galerie'][count($members) - 1]['mediaId'],
        );
        self::assertArrayHasKey('meta', $payload);
        self::assertContains('galerie', $payload['meta']['feldgruppen']);

        unlink($storagePath);
    }

    public function testFeedItemReturnsNotFoundForUnknownIdentifier(): void
    {
        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->with([])
            ->willReturn([]);

        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $mediaRepo->expects(self::never())->method('findByIds');

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager = $this->createMock(SlideshowVideoManagerInterface::class);
        $entityManager    = $this->createMock(EntityManagerInterface::class);

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

        $unknownId = hash('sha1', 'holiday_event|1,2,3');
        $request   = Request::create('/api/feed/' . $unknownId, 'GET');
        $response  = $controller->feedItem($request, $unknownId);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Feed item not found.', $payload['error']);

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
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
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

    public function testTriggerSlideshowQueuesJobAndReturnsStatus(): void
    {
        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $item = new MemoryFeedItem(
            algorithm: 'holiday_event',
            title: 'Winter in Berlin',
            subtitle: 'Lichterzauber an der Spree',
            coverMediaId: 1,
            memberIds: [1, 2],
            score: 0.75,
            params: [
                'group'      => 'city_and_events',
                'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_800],
            ],
        );

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->with([])
            ->willReturn([$item]);

        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $entityManager     = $this->createMock(EntityManagerInterface::class);

        $mediaOne = $this->createMedia(1, '/media/1.jpg', '2024-01-01T10:00:00+00:00');
        $mediaTwo = $this->createMedia(2, '/media/2.jpg', '2024-01-02T11:00:00+00:00');

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([1, 2], false)
            ->willReturn([$mediaOne, $mediaTwo]);

        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(3.5));
        $expectedMap = [1 => $mediaOne, 2 => $mediaTwo];
        $itemId      = hash('sha1', 'holiday_event|1,2');

        $slideshowManager->expects(self::once())
            ->method('ensureForItem')
            ->with(
                self::identicalTo($itemId),
                self::equalTo([1, 2]),
                self::callback(static function (array $map) use ($expectedMap): bool {
                    return $map === $expectedMap;
                }),
                self::identicalTo('Winter in Berlin'),
                self::identicalTo('Lichterzauber an der Spree'),
            )
            ->willReturn(SlideshowVideoStatus::ready('/api/feed/' . $itemId . '/video', 3.5));

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

        $request  = Request::create('/api/feed/' . $itemId . '/video', 'POST');
        $response = $controller->triggerSlideshow($request, $itemId);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('slideshow', $payload);
        self::assertSame('bereit', $payload['slideshow']['status']);
        self::assertSame('/api/feed/' . $itemId . '/video', $payload['slideshow']['url']);
        self::assertSame(1.0, $payload['slideshow']['fortschritt']);

        unlink($storagePath);
    }

    public function testTriggerSlideshowReturnsNotFoundWhenItemIsUnknown(): void
    {
        $clusterRepo = $this->createMock(ClusterRepository::class);
        $clusterRepo->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $item = new MemoryFeedItem(
            algorithm: 'holiday_event',
            title: 'Winter in Berlin',
            subtitle: 'Lichterzauber an der Spree',
            coverMediaId: 3,
            memberIds: [3, 4],
            score: 0.75,
            params: [
                'group'      => 'city_and_events',
                'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_800],
            ],
        );

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::once())
            ->method('build')
            ->with([])
            ->willReturn([$item]);

        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $entityManager     = $this->createMock(EntityManagerInterface::class);

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([3, 4], false)
            ->willReturn([]);

        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(3.5));
        $slideshowManager->expects(self::never())->method('ensureForItem');

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

        $unknownId = hash('sha1', 'holiday_event|1,2');
        $request   = Request::create('/api/feed/' . $unknownId . '/video', 'POST');
        $response  = $controller->triggerSlideshow($request, $unknownId);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Feed item not found.', $payload['error']);

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
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $tempFile = tempnam(sys_get_temp_dir(), 'thumb');
        self::assertIsString($tempFile);
        file_put_contents($tempFile, 'fake');

        $media = new Media($tempFile, 'checksum', 4);
        $media->setThumbnails([320 => $tempFile]);

        $this->assignEntityId($media, 99);

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
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
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
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $original  = tempnam(sys_get_temp_dir(), 'orig');
        $generated = tempnam(sys_get_temp_dir(), 'gen');
        self::assertIsString($original);
        self::assertIsString($generated);
        file_put_contents($original, 'original');
        file_put_contents($generated, 'generated');

        $media = new Media($original, 'checksum-123', 10);
        $this->assignEntityId($media, 12);

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

    public function testThumbnailRegeneratesWhenMetadataIsStale(): void
    {
        $feedBuilder       = $this->createMock(FeedBuilderInterface::class);
        $clusterRepo       = $this->createMock(ClusterRepository::class);
        $mapper            = new ClusterEntityToDraftMapper([]);
        $thumbnailResolver = new ThumbnailPathResolver();
        $mediaRepo         = $this->createMock(MediaRepository::class);
        $thumbnailService  = $this->createMock(ThumbnailServiceInterface::class);
        $slideshowManager  = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager->method('getStatusForItem')->willReturn(SlideshowVideoStatus::unavailable(4.0));
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $original = tempnam(sys_get_temp_dir(), 'orig');
        $generated = tempnam(sys_get_temp_dir(), 'regen');
        self::assertIsString($original);
        self::assertIsString($generated);
        file_put_contents($original, 'original');
        file_put_contents($generated, 'generated');

        $media = new Media($original, 'checksum-987', 11);
        $media->setThumbnails([320 => $original . '-missing.jpg']);
        $this->assignEntityId($media, 44);

        $mediaRepo->expects(self::once())
            ->method('findByIds')
            ->with([44], false)
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

        $request  = Request::create('/api/media/44/thumbnail', 'GET', ['breite' => '320']);
        $response = $controller->thumbnail($request, 44);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($generated, $response->getFilePath());
        self::assertSame([320 => $generated], $media->getThumbnails());

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
        array $spaGestures = [],
        array $spaOffline = [],
        array $spaAnimations = [],
        int $timelineMonths = 12,
    ): array {
        $profileProvider = new FeedPersonalizationProfileProvider([
            'default' => [
                'min_score'             => 0.0,
                'min_members'           => 3,
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
        file_put_contents($storagePath, '[]');

        $preferenceStorage = new FeedUserPreferenceStorage($storagePath);
        $storyboardGenerator = new StoryboardTextGenerator();
        $notificationPlanner = new NotificationPlanner([
            'push' => [
                'lead_times' => ['P0D'],
                'send_time'  => '09:00',
                'timezone'   => 'UTC',
            ],
        ], '09:00', 'UTC');
        $labelProvider = new AlgorithmLabelProvider([
            'holiday_event' => 'Feiertage',
        ]);

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
            $notificationPlanner,
            $labelProvider,
            24,
            120,
            8,
            4,
            640,
            320,
            1024,
            2048,
            3.5,
            0.8,
            [],
            null,
            $timelineMonths,
            $spaGestures,
            $spaOffline,
            $spaAnimations,
        );

        return [$controller, $storagePath];
    }

    private function createMedia(int $id, string $path, string $takenAt): Media
    {
        $media = new Media($path, 'checksum-' . $id, 128);

        $this->assignEntityId($media, $id);

        $timestamp = new DateTimeImmutable($takenAt);
        $media->setTakenAt($timestamp);
        $media->setCapturedLocal($timestamp);
        $media->setTimeSource(TimeSource::EXIF);
        $media->setTzId('UTC');

        return $media;
    }
}
