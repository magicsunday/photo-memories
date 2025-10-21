<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Http;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Http\Controller\FeedController;
use MagicSunday\Memories\Http\Request;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Feed\AlgorithmLabelProvider;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfileProvider;
use MagicSunday\Memories\Service\Feed\FeedUserPreferenceStorage;
use MagicSunday\Memories\Service\Feed\NotificationPlanner;
use MagicSunday\Memories\Service\Feed\StoryboardTextGenerator;
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoManagerInterface;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoStatus;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use MagicSunday\Memories\Test\Support\Memories\MemoryDataset;
use MagicSunday\Memories\Test\Support\Memories\MemoryDatasetLoader;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function file_exists;
use function unlink;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @internal
 */
#[CoversClass(FeedController::class)]
final class FeedStoryboardIntegrationTest extends TestCase
{
    private const string DATASET = 'familienevent';

    #[Test]
    public function itMatchesStoryboardSnapshot(): void
    {
        $fixturesDir = dirname(__DIR__, 3) . '/fixtures/memories';

        $loader   = new MemoryDatasetLoader($fixturesDir);
        $dataset  = $loader->load(self::DATASET);
        $fixtures = $this->buildFeedFixtures($dataset);

        $preferencesPath = tempnam(sys_get_temp_dir(), 'feed-prefs-');
        self::assertIsString($preferencesPath);
        @unlink($preferencesPath);

        $profileProvider = new FeedPersonalizationProfileProvider([
            'default' => [
                'min_score'            => 0.0,
                'min_members'          => 1,
                'max_per_day'          => 12,
                'max_total'            => 60,
                'max_per_algorithm'    => 12,
                'quality_floor'        => 0.0,
                'people_coverage_min'  => 0.0,
                'recent_days'          => 0,
                'stale_days'           => 0,
                'recent_score_bonus'   => 0.0,
                'stale_score_penalty'  => 0.0,
            ],
        ]);

        $preferenceStorage = new FeedUserPreferenceStorage($preferencesPath);

        $clusterRepository = $this->createMock(ClusterRepository::class);
        $clusterRepository
            ->expects(self::once())
            ->method('findLatest')
            ->with(96)
            ->willReturn([]);

        $clusterMapper = new ClusterEntityToDraftMapper();

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder
            ->expects(self::once())
            ->method('build')
            ->with([], $profileProvider->getProfile('default'), self::anything(), self::anything())
            ->willReturn($fixtures['items']);

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->method('findByIds')
            ->willReturnCallback(
                static function (array $ids) use ($fixtures): array {
                    $result = [];
                    foreach ($ids as $id) {
                        if (!is_int($id)) {
                            continue;
                        }

                        if (!array_key_exists($id, $fixtures['media'])) {
                            continue;
                        }

                        $result[] = $fixtures['media'][$id];
                    }

                    return $result;
                }
            );

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $slideshowManager = $this->createMock(SlideshowVideoManagerInterface::class);
        $slideshowManager
            ->method('getStatusForItem')
            ->willReturn(SlideshowVideoStatus::unavailable(3.5));

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $controller = new FeedController(
            feedBuilder: $feedBuilder,
            clusterRepository: $clusterRepository,
            clusterMapper: $clusterMapper,
            thumbnailResolver: new ThumbnailPathResolver(),
            mediaRepository: $mediaRepository,
            thumbnailService: $thumbnailService,
            slideshowManager: $slideshowManager,
            entityManager: $entityManager,
            profileProvider: $profileProvider,
            preferenceStorage: $preferenceStorage,
            storyboardTextGenerator: new StoryboardTextGenerator('de', ['de']),
            notificationPlanner: new NotificationPlanner(),
            algorithmLabelProvider: new AlgorithmLabelProvider([
                'familienevent_story_1' => 'Familienevent (Teil 1)',
                'familienevent_story_2' => 'Familienevent (Teil 2)',
                'familienevent_story_3' => 'Familienevent (Teil 3)',
            ]),
            slideshowTransitions: $dataset->getStoryboardTransitions(),
        );

        $request = Request::create(
            path: '/api/feed',
            method: 'GET',
            query: ['felder' => 'basis,galerie,storyboard'],
            headers: ['Accept-Language' => 'de-DE,de;q=0.9'],
        );

        try {
            $response = $controller->feed($request);
            self::assertSame(200, $response->getStatusCode());

            $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertArrayHasKey('items', $payload);

            $storyboards = array_map(
                static fn (array $item): ?array => $item['storyboard'] ?? null,
                $payload['items'],
            );

            $snapshotPath = __DIR__ . '/__snapshots__/feed_storyboard.json';
            $encoded = json_encode($storyboards, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            self::assertJsonStringEqualsJsonFile($snapshotPath, $encoded);
        } finally {
            if (is_string($preferencesPath) && file_exists($preferencesPath)) {
                @unlink($preferencesPath);
            }
        }
    }

    /**
     * @return array{items: list<MemoryFeedItem>, media: array<int, Media>}
     */
    private function buildFeedFixtures(MemoryDataset $dataset): array
    {
        $mediaByFilename = [];
        $mediaById       = [];
        $items           = [];
        $nextMediaId     = 1;

        foreach ($dataset->getClusters() as $clusterIndex => $cluster) {
            if (!is_array($cluster['items'] ?? null)) {
                continue;
            }

            $memberIds      = [];
            $clusterTags    = [];
            $fromTimestamp  = null;
            $toTimestamp    = null;
            $clusterPlace   = null;
            $coverMediaId   = null;

            foreach ($cluster['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $filename = (string) ($item['filename'] ?? '');
                if ($filename === '') {
                    continue;
                }

                if (!array_key_exists($filename, $mediaByFilename)) {
                    $path = $dataset->resolvePreviewPath($filename);

                    $media = $this->makeMedia(
                        id: $nextMediaId,
                        path: $path,
                        takenAt: new DateTimeImmutable((string) $item['taken_at']),
                    );

                    $tags = $this->normaliseStringList($item['tags'] ?? []);
                    if ($tags !== []) {
                        $media->setKeywords($tags);
                        $media->setSceneTags(array_map(
                            static fn (string $label): array => ['label' => $label, 'score' => 0.85],
                            $tags,
                        ));
                    }

                    $people = $this->normaliseStringList($item['people'] ?? []);
                    if ($people !== []) {
                        $media->setPersons($people);
                    }

                    $media->setThumbnails([
                        320 => $path,
                        640 => $path,
                    ]);

                    $mediaByFilename[$filename] = $nextMediaId;
                    $mediaById[$nextMediaId]    = $media;
                    ++$nextMediaId;
                }

                $mediaId = $mediaByFilename[$filename];
                $memberIds[] = $mediaId;

                $tags = $this->normaliseStringList($item['tags'] ?? []);
                if ($tags !== []) {
                    $clusterTags = array_merge($clusterTags, $tags);
                }

                $roles = is_array($item['roles'] ?? null) ? $item['roles'] : [];
                if ($coverMediaId === null && in_array('key', $roles, true)) {
                    $coverMediaId = $mediaId;
                }

                $takenAt = new DateTimeImmutable((string) $item['taken_at']);
                $timestamp = $takenAt->getTimestamp();
                $fromTimestamp = $fromTimestamp === null ? $timestamp : min($fromTimestamp, $timestamp);
                $toTimestamp   = $toTimestamp === null ? $timestamp : max($toTimestamp, $timestamp);

                if ($clusterPlace === null && is_array($item['location'] ?? null)) {
                    $city    = $item['location']['city'] ?? null;
                    $country = $item['location']['country'] ?? null;
                    if (is_string($city) && is_string($country)) {
                        $clusterPlace = sprintf('%s (%s)', $city, $country);
                    }
                }
            }

            if ($memberIds === []) {
                continue;
            }

            if ($coverMediaId === null) {
                $coverMediaId = $memberIds[0];
            }

            $uniqueTags = array_values(array_unique($clusterTags));

            $params = [
                'group'      => self::DATASET,
                'time_range' => [
                    'from' => $fromTimestamp,
                    'to'   => $toTimestamp,
                ],
                'keywords'   => $uniqueTags,
                'scene_tags' => array_map(
                    static fn (string $label): array => ['label' => $label, 'score' => 0.75],
                    $uniqueTags,
                ),
            ];

            if ($clusterPlace !== null) {
                $params['place'] = $clusterPlace;
            }

            $items[] = new MemoryFeedItem(
                algorithm: sprintf('%s_story_%d', self::DATASET, $clusterIndex + 1),
                title: (string) ($cluster['title'] ?? ''),
                subtitle: (string) ($cluster['summary'] ?? ''),
                coverMediaId: $coverMediaId,
                memberIds: $memberIds,
                score: max(0.1, 0.95 - ($clusterIndex * 0.1)),
                params: $params,
            );
        }

        return [
            'items' => $items,
            'media' => $mediaById,
        ];
    }

    /**
     * @param mixed $values
     *
     * @return list<string>
     */
    private function normaliseStringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            if (in_array($trimmed, $result, true)) {
                continue;
            }

            $result[] = $trimmed;
        }

        return $result;
    }
}
