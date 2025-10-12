<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Http\Controller;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Http\Request;
use MagicSunday\Memories\Http\Response\BinaryFileResponse;
use MagicSunday\Memories\Http\Response\JsonResponse;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\AlgorithmLabelProvider;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfileProvider;
use MagicSunday\Memories\Service\Feed\FeedUserPreferenceStorage;
use MagicSunday\Memories\Service\Feed\FeedUserPreferences;
use MagicSunday\Memories\Service\Feed\NotificationPlanner;
use MagicSunday\Memories\Service\Feed\StoryboardTextGenerator;
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoManagerInterface;
use MagicSunday\Memories\Service\Slideshow\TransitionSequenceGenerator;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoStatus;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use RuntimeException;
use IntlDateFormatter;
use IntlException;

use function array_fill_keys;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_replace;
use function array_slice;
use function array_unique;
use function explode;
use function array_values;
use function count;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
use function is_iterable;
use function is_numeric;
use function is_string;
use function max;
use function krsort;
use function min;
use function preg_split;
use function round;
use function sort;
use function str_ends_with;
use function sprintf;
use function trim;
use const SORT_STRING;

/**
 * HTTP controller to expose the Rückblick feed and thumbnail media.
 */
final class FeedController
{
    private const DEFAULT_ITEM_FIELD_GROUPS = [
        'basis',
        'zeit',
        'galerie',
        'kontext',
        'zusatzdaten',
        'slideshow',
        'storyboard',
        'benachrichtigungen',
    ];

    private const DEFAULT_META_FIELD_GROUPS = [
        'basis',
        'pagination',
        'filter',
        'personalisierung',
        'strategien',
    ];

    private const META_FIELD_KEY_MAP = [
        'basis'            => ['erstelltAm', 'erstelltAmText', 'hinweisErstelltAm', 'gesamtVerfuegbar', 'anzahlGeliefert'],
        'pagination'       => ['pagination'],
        'filter'           => ['filter', 'filterKonfiguration'],
        'personalisierung' => ['personalisierung'],
        'strategien'       => ['verfuegbareStrategien', 'verfuegbareGruppen', 'labelMapping'],
    ];

    /**
     * @var array<int, Media|null>
     */
    private array $mediaCache = [];

    public function __construct(
        private readonly FeedBuilderInterface $feedBuilder,
        private readonly ClusterRepository $clusterRepository,
        private readonly ClusterEntityToDraftMapper $clusterMapper,
        private readonly ThumbnailPathResolver $thumbnailResolver,
        private readonly MediaRepository $mediaRepository,
        private readonly ThumbnailServiceInterface $thumbnailService,
        private readonly SlideshowVideoManagerInterface $slideshowManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly FeedPersonalizationProfileProvider $profileProvider,
        private readonly FeedUserPreferenceStorage $preferenceStorage,
        private readonly StoryboardTextGenerator $storyboardTextGenerator,
        private readonly NotificationPlanner $notificationPlanner,
        private readonly AlgorithmLabelProvider $algorithmLabelProvider,
        private int $defaultFeedLimit = 24,
        private int $maxFeedLimit = 120,
        private int $previewImageCount = 8,
        private int $clusterFetchMultiplier = 4,
        private int $defaultCoverWidth = 640,
        private int $defaultMemberWidth = 320,
        private int $defaultLightboxWidth = 1024,
        private int $maxThumbnailWidth = 2048,
        private float $slideshowImageDuration = 3.5,
        private float $slideshowTransitionDuration = 0.8,
        private array $slideshowTransitions = [],
        private ?string $slideshowMusic = null,
        private int $spaTimelineMonths = 12,
        private array $spaGestureConfig = [],
        private array $spaOfflineConfig = [],
        private array $spaAnimationConfig = [],
    ) {
        if ($this->defaultFeedLimit < 1) {
            $this->defaultFeedLimit = 24;
        }

        if ($this->maxFeedLimit < $this->defaultFeedLimit) {
            $this->maxFeedLimit = $this->defaultFeedLimit;
        }

        if ($this->previewImageCount < 1) {
            $this->previewImageCount = 8;
        }

        if ($this->clusterFetchMultiplier < 1) {
            $this->clusterFetchMultiplier = 3;
        }

        if ($this->defaultCoverWidth < 64) {
            $this->defaultCoverWidth = 640;
        }

        if ($this->defaultMemberWidth < 64) {
            $this->defaultMemberWidth = 320;
        }

        if ($this->defaultLightboxWidth < $this->defaultMemberWidth) {
            $this->defaultLightboxWidth = $this->defaultMemberWidth;
        }

        if ($this->maxThumbnailWidth < $this->defaultCoverWidth) {
            $this->maxThumbnailWidth = $this->defaultCoverWidth;
        }

        if ($this->defaultLightboxWidth > $this->maxThumbnailWidth) {
            $this->defaultLightboxWidth = $this->maxThumbnailWidth;
        }

        if ($this->slideshowImageDuration <= 0.0) {
            $this->slideshowImageDuration = 3.5;
        }

        if ($this->slideshowTransitionDuration < 0.0) {
            $this->slideshowTransitionDuration = 0.8;
        }

        $transitions = [];
        foreach ($this->slideshowTransitions as $transition) {
            if (!is_string($transition)) {
                continue;
            }

            $trimmed = trim($transition);
            if ($trimmed === '') {
                continue;
            }

            $transitions[] = $trimmed;
        }

        $this->slideshowTransitions = $transitions;

        if (!is_string($this->slideshowMusic) || trim($this->slideshowMusic) === '') {
            $this->slideshowMusic = null;
        } else {
            $this->slideshowMusic = trim($this->slideshowMusic);
        }

        if ($this->spaTimelineMonths < 1) {
            $this->spaTimelineMonths = 6;
        }

        $this->spaGestureConfig   = $this->sanitizeGestureConfig($this->spaGestureConfig);
        $this->spaOfflineConfig   = $this->sanitizeOfflineConfig($this->spaOfflineConfig);
        $this->spaAnimationConfig = $this->sanitizeAnimationConfig($this->spaAnimationConfig);
    }

    public function feed(Request $request): JsonResponse
    {
        $dateInfo = $this->resolveDateFilter($this->normalizeString($request->getQueryParam('datum')));
        if ($dateInfo['error'] !== null) {
            return new JsonResponse([
                'error' => $dateInfo['error'],
            ], 400);
        }

        $result = $this->buildFeedResult($request, $dateInfo['date']);

        return new JsonResponse([
            'meta'  => $result['meta'],
            'items' => $result['items'],
        ]);
    }

    public function feedItem(Request $request, string $itemId): JsonResponse
    {
        $dateInfo = $this->resolveDateFilter($this->normalizeString($request->getQueryParam('datum')));
        if ($dateInfo['error'] !== null) {
            return new JsonResponse([
                'error' => $dateInfo['error'],
            ], 400);
        }

        $fieldSelection = $this->resolveFieldSelection($request);
        $result         = $this->buildFeedResult($request, $dateInfo['date'], $fieldSelection);
        $baseUrl        = rtrim($request->getBaseUrl(), '/');

        foreach ($result['matchingItems'] as $item) {
            if ($this->createItemId($item) !== $itemId) {
                continue;
            }

            $payload = $this->transformItem(
                $item,
                $baseUrl,
                $result['now'],
                $result['preferences'],
                $result['locale'],
                $fieldSelection['itemGroups'],
                true,
            );

            return new JsonResponse([
                'item' => $payload,
                'meta' => [
                    'erstelltAm'  => $result['now']->format(DateTimeInterface::ATOM),
                    'feldgruppen' => $fieldSelection['itemGroups'],
                ],
            ]);
        }

        return new JsonResponse([
            'error' => 'Feed item not found.',
        ], 404);
    }

    public function spaBootstrap(Request $request): JsonResponse
    {
        $dateInfo = $this->resolveDateFilter($this->normalizeString($request->getQueryParam('datum')));
        if ($dateInfo['error'] !== null) {
            return new JsonResponse([
                'error' => $dateInfo['error'],
            ], 400);
        }

        $result = $this->buildFeedResult($request, $dateInfo['date']);

        $components = [
            'fuerDich'    => $this->buildFuerDichComponent($result['items'], $result['meta']),
            'timeline'    => $this->buildTimelineComponent($result['matchingItems'], $result['locale']),
            'storyViewer' => $this->buildStoryViewerComponent($result['items']),
            'offline'     => $this->buildOfflineComponent($result['now'], $result['preferences']),
        ];

        return new JsonResponse([
            'meta'       => [
                'erstelltAm' => $result['meta']['erstelltAm'] ?? $result['now']->format(DateTimeInterface::ATOM),
                'locale'     => $result['locale'],
            ],
            'components' => $components,
        ]);
    }

    public function triggerSlideshow(Request $request, string $itemId): JsonResponse
    {
        $dateInfo = $this->resolveDateFilter($this->normalizeString($request->getQueryParam('datum')));
        if ($dateInfo['error'] !== null) {
            return new JsonResponse([
                'error' => $dateInfo['error'],
            ], 400);
        }

        $result = $this->buildFeedResult($request, $dateInfo['date']);

        foreach ($result['matchingItems'] as $item) {
            if ($this->createItemId($item) !== $itemId) {
                continue;
            }

            $memberIds = $item->getMemberIds();
            $mediaMap  = $this->loadMediaMap($memberIds, $item->getAlgorithm() === 'video_stories');
            $status    = $this->slideshowManager->ensureForItem(
                $itemId,
                $memberIds,
                $mediaMap,
                $item->getTitle(),
                $item->getSubtitle(),
            );

            return new JsonResponse([
                'slideshow' => $this->enrichSlideshowStatus($status),
            ]);
        }

        return new JsonResponse([
            'error' => 'Feed item not found.',
        ], 404);
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     meta: array<string, mixed>,
     *     matchingItems: list<MemoryFeedItem>,
     *     preferences: FeedUserPreferences,
     *     locale: string,
     *     now: DateTimeImmutable,
     *     fieldSelection: array{itemGroups: list<string>, metaGroups: list<string>}
     * }
     */
    private function buildFeedResult(
        Request $request,
        ?DateTimeImmutable $filterDate,
        ?array $fieldSelection = null,
    ): array
    {
        $limit    = $this->normalizeLimit($request->getQueryParam('limit'));
        $minScore = $this->normalizeFloat($request->getQueryParam('score'));
        $strategy = $this->normalizeString($request->getQueryParam('strategie'));
        $cursor   = $this->normalizeString($request->getQueryParam('cursor'));

        $profileKey = $this->normalizeString($request->getQueryParam('profil'));
        $userId     = $this->normalizeString($request->getQueryParam('nutzer')) ?? 'standard';
        $profile    = $this->profileProvider->getProfile($profileKey);
        $preferences = $this->preferenceStorage->getPreferences($userId, $profile->getKey());
        $locale      = $this->resolveLocale($request);

        $clusterLimit = max($limit * $this->clusterFetchMultiplier, $limit);
        $clusters     = $this->clusterRepository->findLatest($clusterLimit);
        $drafts       = $this->clusterMapper->mapMany($clusters);
        $items        = $this->feedBuilder->build($drafts, $profile);
        $items        = array_values(array_filter(
            $items,
            static function (MemoryFeedItem $item) use ($preferences): bool {
                return !$preferences->isAlgorithmOptedOut($item->getAlgorithm());
            },
        ));

        $filtered = array_values(array_filter(
            $items,
            function (MemoryFeedItem $item) use ($minScore, $strategy, $filterDate): bool {
                if ($minScore !== null && $item->getScore() < $minScore) {
                    return false;
                }

                if ($strategy !== null && $item->getAlgorithm() !== $strategy) {
                    return false;
                }

                if ($filterDate !== null && !$this->matchesDate($item, $filterDate)) {
                    return false;
                }

                return true;
            }
        ));

        $filtered      = $this->applyCursor($filtered, $cursor);
        $matchingItems = $filtered;
        $matchingCount = count($matchingItems);
        $pagedItems    = array_slice($matchingItems, 0, $limit);

        $availableStrategies = $this->collectStrategies($matchingItems);
        $availableGroups     = $this->collectGroups($matchingItems);

        $now     = new DateTimeImmutable();
        $baseUrl = rtrim($request->getBaseUrl(), '/');

        if ($fieldSelection === null) {
            $fieldSelection = $this->resolveFieldSelection($request);
        }

        $itemGroups = $fieldSelection['itemGroups'];

        /** @var list<array<string, mixed>> $data */
        $data = array_map(
            fn (MemoryFeedItem $item): array => $this->transformItem($item, $baseUrl, $now, $preferences, $locale, $itemGroups),
            $pagedItems,
        );

        $hasMore    = count($data) < $matchingCount;
        $nextCursor = $this->createCursor($pagedItems);

        $meta = [
            'erstelltAm'            => $now->format(DateTimeInterface::ATOM),
            'erstelltAmText'        => $this->formatLocalizedDate($now),
            'hinweisErstelltAm'     => $this->formatRelativeTime($now, $now),
            'gesamtVerfuegbar'      => $matchingCount,
            'anzahlGeliefert'       => count($data),
            'verfuegbareStrategien' => $availableStrategies,
            'verfuegbareGruppen'    => $availableGroups,
            'labelMapping'          => $this->buildLabelMapping(),
            'pagination'            => [
                'hatWeitere'      => $hasMore,
                'nextCursor'      => ($hasMore || $cursor !== null) ? $nextCursor : null,
                'limitEmpfehlung' => $this->defaultFeedLimit,
                'cursor'          => $cursor,
            ],
            'personalisierung'      => [
                'nutzer'             => $userId,
                'profil'             => $profile->getKey(),
                'verfuegbareProfile' => $this->profileProvider->listProfiles(),
                'schwellenwerte'     => $profile->describe(),
                'favoriten'          => $preferences->getFavourites(),
                'optOutAlgorithmen'  => $preferences->getHiddenAlgorithms(),
            ],
            'filter'                => [
                'score'     => $minScore,
                'strategie' => $strategy,
                'datum'     => $filterDate?->format('Y-m-d'),
                'limit'     => $limit,
                'profil'    => $profile->getKey(),
                'nutzer'    => $userId,
                'cursor'    => $cursor,
            ],
            'filterKonfiguration'   => [
                'unterstuetzt' => ['score', 'strategie', 'datum', 'profil', 'nutzer', 'cursor'],
                'cursor'       => [
                    'aktiv'  => $cursor,
                    'schema' => 'time:<unix>|media:<mediaId>',
                ],
            ],
        ];

        $meta = $this->filterMetaPayload($meta, $fieldSelection['metaGroups']);

        return [
            'items'         => $data,
            'meta'          => $meta,
            'matchingItems' => $matchingItems,
            'preferences'   => $preferences,
            'locale'        => $locale,
            'now'           => $now,
            'fieldSelection'=> $fieldSelection,
        ];
    }

    /**
     * @return array{itemGroups: list<string>, metaGroups: list<string>}
     */
    private function resolveFieldSelection(Request $request): array
    {
        $itemGroups = $this->parseFieldList(
            $this->normalizeString($request->getQueryParam('felder')),
            self::DEFAULT_ITEM_FIELD_GROUPS,
        );

        if ($itemGroups === []) {
            $itemGroups = self::DEFAULT_ITEM_FIELD_GROUPS;
        }

        if (!in_array('basis', $itemGroups, true)) {
            array_unshift($itemGroups, 'basis');
        }

        $itemGroups = array_values(array_unique($itemGroups));

        $metaGroups = $this->parseFieldList(
            $this->normalizeString($request->getQueryParam('metaFelder')),
            array_keys(self::META_FIELD_KEY_MAP),
        );

        if ($metaGroups === []) {
            $metaGroups = self::DEFAULT_META_FIELD_GROUPS;
        }

        if (!in_array('basis', $metaGroups, true)) {
            array_unshift($metaGroups, 'basis');
        }

        $metaGroups = array_values(array_unique($metaGroups));

        return [
            'itemGroups' => $itemGroups,
            'metaGroups' => $metaGroups,
        ];
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private function parseFieldList(?string $value, array $allowed): array
    {
        if ($value === null) {
            return [];
        }

        $tokens = preg_split('/[,\s]+/', $value);
        if ($tokens === false) {
            $tokens = [];
        }
        $result = [];

        foreach ($tokens as $token) {
            $trimmed = trim($token);
            if ($trimmed === '' || !in_array($trimmed, $allowed, true)) {
                continue;
            }

            if (!in_array($trimmed, $result, true)) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string>         $selectedGroups
     *
     * @return array<string, mixed>
     */
    private function filterMetaPayload(array $meta, array $selectedGroups): array
    {
        $groupLookup = array_fill_keys($selectedGroups, true);
        $orderedKeys = [];

        foreach (self::META_FIELD_KEY_MAP as $group => $keys) {
            if (!isset($groupLookup[$group])) {
                continue;
            }

            foreach ($keys as $key) {
                $orderedKeys[] = $key;
            }
        }

        $filtered = [];
        foreach ($orderedKeys as $key) {
            if (array_key_exists($key, $meta)) {
                $filtered[$key] = $meta[$key];
            }
        }

        return $filtered;
    }

    /**
     * @param list<MemoryFeedItem> $items
     *
     * @return list<MemoryFeedItem>
     */
    private function applyCursor(array $items, ?string $cursor): array
    {
        if ($cursor === null) {
            return array_values($items);
        }

        $parts = explode(':', $cursor, 2);
        if (count($parts) !== 2) {
            return array_values($items);
        }

        [$type, $rawValue] = $parts;
        if ($type === 'time' && is_numeric($rawValue)) {
            $threshold = (int) $rawValue;

            return array_values(array_filter(
                $items,
                function (MemoryFeedItem $item) use ($threshold): bool {
                    $params = $item->getParams();
                    $range  = $params['time_range'] ?? null;

                    if (is_array($range)) {
                        $from = $range['from'] ?? null;
                        if (is_numeric($from) && (int) $from >= $threshold) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
        }

        if ($type === 'media' && is_numeric($rawValue)) {
            $threshold = (int) $rawValue;
            $result    = [];
            $skip      = true;

            foreach ($items as $item) {
                if ($skip) {
                    if (in_array($threshold, $item->getMemberIds(), true)) {
                        $skip = false;
                    }

                    continue;
                }

                $result[] = $item;
            }

            return array_values($result);
        }

        return array_values($items);
    }

    /**
     * @return array{date: ?DateTimeImmutable, error: ?string}
     */
    private function resolveDateFilter(?string $dateParam): array
    {
        if ($dateParam === null) {
            return ['date' => null, 'error' => null];
        }

        $filterDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateParam);
        $errors     = DateTimeImmutable::getLastErrors();

        $hasErrors = ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0;
        if (!$filterDate instanceof DateTimeImmutable || $hasErrors) {
            return ['date' => null, 'error' => 'Invalid date filter format, expected YYYY-MM-DD.'];
        }

        return ['date' => $filterDate, 'error' => null];
    }

    public function thumbnail(Request $request, int $mediaId): JsonResponse|BinaryFileResponse
    {
        $width = $this->normalizeWidth($request->getQueryParam('breite'));

        $mediaList = $this->mediaRepository->findByIds([$mediaId]);
        $media     = $mediaList !== [] ? $mediaList[0] : null;

        if (!$media instanceof Media) {
            return new JsonResponse([
                'error' => 'Media not found.',
            ], 404);
        }

        $path = $this->resolveOrGenerateThumbnail($media, $width);
        if (!is_string($path)) {
            return new JsonResponse([
                'error' => 'Thumbnail not available.',
            ], 404);
        }

        $response = new BinaryFileResponse($path);
        $response->setHeader('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    private function normalizeLimit(?string $value): int
    {
        if ($value === null) {
            return $this->defaultFeedLimit;
        }

        $int = (int) $value;
        if ($int < 1) {
            return $this->defaultFeedLimit;
        }

        return min($int, $this->maxFeedLimit);
    }

    private function normalizeFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function normalizeWidth(?string $value): int
    {
        if ($value === null) {
            return $this->defaultCoverWidth;
        }

        if (!is_numeric($value)) {
            return $this->defaultCoverWidth;
        }

        $int = (int) $value;
        if ($int < 64) {
            return 64;
        }

        return min($int, $this->maxThumbnailWidth);
    }

    private function resolveLocale(Request $request): string
    {
        $override = $this->normalizeString($request->getQueryParam('sprache'));
        if ($override !== null) {
            return $this->storyboardTextGenerator->normaliseLocale($override);
        }

        $header = $request->getHeader('accept-language');
        if ($header !== null) {
            foreach (explode(',', $header) as $segment) {
                $trimmed = trim($segment);
                if ($trimmed === '') {
                    continue;
                }

                $parts = explode(';', $trimmed);
                $language = trim($parts[0] ?? '');
                if ($language === '') {
                    continue;
                }

                return $this->storyboardTextGenerator->normaliseLocale($language);
            }
        }

        return $this->storyboardTextGenerator->getDefaultLocale();
    }

    private function resolveOrGenerateThumbnail(Media $media, int $width): ?string
    {
        $resolved = $this->thumbnailResolver->resolveBest($media, $width);
        if (is_string($resolved) && $resolved !== $media->getPath()) {
            return $resolved;
        }

        $existing            = $media->getThumbnails();
        $hasUsableThumbnails = false;

        if (is_array($existing) && $existing !== []) {
            foreach ($existing as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }

                if (is_file($path)) {
                    $hasUsableThumbnails = true;

                    break;
                }
            }
        }

        if ($hasUsableThumbnails && is_string($resolved)) {
            return $resolved;
        }

        try {
            $generated = $this->thumbnailService->generateAll($media->getPath(), $media);
        } catch (RuntimeException) {
            return $resolved;
        }

        if ($generated === []) {
            return $resolved;
        }

        $current = $media->getThumbnails();
        if (is_array($current) && $current !== []) {
            $media->setThumbnails(array_replace($current, $generated));
        } else {
            $media->setThumbnails($generated);
        }

        $this->entityManager->flush();

        $resolved = $this->thumbnailResolver->resolveBest($media, $width);

        if (is_string($resolved)) {
            return $resolved;
        }

        return null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Media>
     */
    private function loadMediaMap(array $ids, bool $onlyVideos = false): array
    {
        if ($ids === []) {
            return [];
        }

        $missing = [];
        foreach ($ids as $id) {
            if (!array_key_exists($id, $this->mediaCache)) {
                $missing[$id] = true;
            }
        }

        if ($missing !== []) {
            $mediaItems = $this->mediaRepository->findByIds(array_keys($missing), $onlyVideos);
            foreach ($mediaItems as $media) {
                $this->mediaCache[$media->getId()] = $media;
                unset($missing[$media->getId()]);
            }

            if ($missing !== []) {
                foreach (array_keys($missing) as $id) {
                    $this->mediaCache[$id] = null;
                }
            }
        }

        /** @var array<int, Media|null> $orderedCache */
        $orderedCache = array_replace(
            array_fill_keys($ids, null),
            array_intersect_key($this->mediaCache, array_flip($ids)),
        );

        /** @var array<int, Media> $result */
        $result = array_filter(
            $orderedCache,
            static fn (?Media $media): bool => $media instanceof Media,
        );

        return $result;
    }

    private function formatTakenAt(?Media $media): ?string
    {
        if (!$media instanceof Media) {
            return null;
        }

        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $takenAt->format(DateTimeInterface::ATOM);
    }

    private function transformItem(
        MemoryFeedItem $item,
        string $baseUrl,
        DateTimeImmutable $reference,
        FeedUserPreferences $preferences,
        string $locale,
        array $selectedGroups,
        bool $includeAllGalleryMembers = false,
    ): array {
        $coverId = $item->getCoverMediaId();
        $members = $item->getMemberIds();

        $selectedMembers = $includeAllGalleryMembers ? $members : array_slice($members, 0, $this->previewImageCount);
        $mediaIdsToLoad  = $includeAllGalleryMembers ? $members : $selectedMembers;
        if ($coverId !== null && !in_array($coverId, $mediaIdsToLoad, true)) {
            $mediaIdsToLoad[] = $coverId;
        }

        $groupSelection = array_fill_keys($selectedGroups, true);

        $onlyVideos     = $item->getAlgorithm() === 'video_stories';
        $memberPayload  = [];
        $memberMediaMap = $this->loadMediaMap($mediaIdsToLoad, $onlyVideos);

        if (isset($groupSelection['galerie']) || isset($groupSelection['storyboard'])) {
            foreach ($selectedMembers as $memberId) {
                $media = $memberMediaMap[$memberId] ?? null;

                $memberPayload[] = $this->buildGalleryEntry(
                    $memberId,
                    $media,
                    $baseUrl,
                    $reference,
                    $item->getParams(),
                );
            }
        }

        $coverMedia    = $coverId !== null ? ($memberMediaMap[$coverId] ?? null) : null;
        $itemId        = $this->createItemId($item);
        $slideshow     = null;
        $timeRange     = isset($groupSelection['zeit']) ? $this->extractTimeRange($item, $reference) : null;
        $coverAltText  = null;

        if (isset($groupSelection['slideshow'])) {
            $slideshow = $this->slideshowManager->getStatusForItem($itemId);
        }

        if ($memberPayload !== []) {
            foreach ($memberPayload as $entry) {
                if (($entry['mediaId'] ?? null) === $coverId) {
                    $coverAltText = $entry['altText'] ?? null;

                    break;
                }
            }
        }

        if ($coverAltText === null) {
            $coverContext = $this->buildMediaContext($coverMedia, $item->getParams());
            $coverAltText = $this->generateAltText($coverMedia, $coverContext);
        }

        $payload = [
            'id'                 => $itemId,
            'algorithmus'        => $item->getAlgorithm(),
            'algorithmusLabel'   => $this->algorithmLabelProvider->getLabel($item->getAlgorithm()),
            'gruppe'             => $this->extractGroup($item),
            'titel'              => $item->getTitle(),
            'untertitel'         => $item->getSubtitle(),
            'score'              => $item->getScore(),
            'favorit'            => $preferences->isFavourite($itemId),
            'coverMediaId'       => $coverId,
            'cover'              => $coverId !== null ? $this->buildThumbnailUrl($coverId, $this->defaultCoverWidth, $baseUrl) : null,
            'coverAufgenommenAm' => $this->formatTakenAt($coverMedia),
            'coverAufgenommenAmText' => $this->formatMediaDateText($coverMedia),
            'coverHinweisAufgenommenAm' => $this->formatRelativeTakenAt($coverMedia, $reference),
            'coverAbmessungen'   => $this->extractDimensions($coverMedia),
            'mitglieder'         => $includeAllGalleryMembers ? $members : $selectedMembers,
        ];

        if ($coverAltText !== null) {
            $payload['coverAltText'] = $coverAltText;
        }

        if (isset($groupSelection['galerie'])) {
            $payload['galerie'] = $memberPayload;
        }

        if (isset($groupSelection['zeit'])) {
            $payload['zeitspanne'] = $timeRange;
        }

        if (isset($groupSelection['zusatzdaten'])) {
            $payload['zusatzdaten'] = $item->getParams();
        }

        if (isset($groupSelection['kontext'])) {
            $payload['kontext'] = $this->buildClusterContext($item->getParams());
        }

        if (isset($groupSelection['slideshow']) && $slideshow instanceof SlideshowVideoStatus) {
            $payload['slideshow'] = $this->enrichSlideshowStatus($slideshow);
        }

        if (isset($groupSelection['storyboard'])) {
            $payload['storyboard'] = $this->buildStoryboard($memberPayload, $item->getParams(), $locale);
        }

        if (isset($groupSelection['benachrichtigungen'])) {
            $payload['benachrichtigungen'] = $this->notificationPlanner->planForItem($item, $reference);
        }

        return $payload;
    }

    /**
     * @param list<array<string,mixed>> $memberPayload
     */
    private function buildStoryboard(array $memberPayload, array $clusterParams, string $locale): ?array
    {
        if ($memberPayload === []) {
            return null;
        }

        $slides = [];

        $mediaIds = [];
        $imagePaths = [];
        foreach ($memberPayload as $entry) {
            $mediaId = $entry['mediaId'] ?? null;
            if (!is_int($mediaId)) {
                continue;
            }

            $mediaIds[] = $mediaId;

            $thumbnail = $entry['thumbnail'] ?? null;
            $imagePaths[] = is_string($thumbnail) ? trim($thumbnail) : '';
        }

        if ($mediaIds === []) {
            return null;
        }

        $texts = $this->storyboardTextGenerator->generate($memberPayload, $clusterParams, $locale);
        $title = trim($texts['title']);
        $description = trim($texts['description']);

        $transitionSequence = TransitionSequenceGenerator::generate(
            $this->slideshowTransitions,
            $mediaIds,
            $imagePaths,
            count($mediaIds),
            $title !== '' ? $title : null,
            $description !== '' ? $description : null
        );
        $transitionIndex = 0;

        foreach ($memberPayload as $entry) {
            $mediaId = $entry['mediaId'] ?? null;
            if (!is_int($mediaId)) {
                continue;
            }

            $slide = [
                'mediaId'        => $mediaId,
                'dauerSekunden'  => $this->slideshowImageDuration,
                'thumbnail'      => $entry['thumbnail'] ?? null,
                'uebergang'      => $this->resolveStoryboardTransition($transitionSequence[$transitionIndex] ?? null),
            ];

            ++$transitionIndex;

            $beschreibung = $entry['beschreibung'] ?? null;
            if (is_string($beschreibung) && trim($beschreibung) !== '') {
                $slide['beschreibung'] = trim($beschreibung);
            }

            $aufgenommen = $entry['aufgenommenAmText'] ?? null;
            if (is_string($aufgenommen) && $aufgenommen !== '') {
                $slide['aufgenommenAmText'] = $aufgenommen;
            }

            $hinweis = $entry['hinweisAufgenommenAm'] ?? null;
            if (is_string($hinweis) && $hinweis !== '') {
                $slide['hinweis'] = $hinweis;
            }

            if (isset($entry['personen']) && is_array($entry['personen']) && $entry['personen'] !== []) {
                $slide['personen'] = $entry['personen'];
            }

            if (isset($entry['szenen']) && is_array($entry['szenen']) && $entry['szenen'] !== []) {
                $slide['szenen'] = $entry['szenen'];
            }

            if (isset($entry['schlagwoerter']) && is_array($entry['schlagwoerter']) && $entry['schlagwoerter'] !== []) {
                $slide['schlagwoerter'] = $entry['schlagwoerter'];
            }

            if (isset($entry['ort']) && is_array($entry['ort'])) {
                $slide['ort'] = $entry['ort'];
            }

            $slides[] = $slide;
        }

        if ($slides === []) {
            return null;
        }

        $payload = [
            'dauerSekunden'        => $this->slideshowImageDuration,
            'uebergangSekunden'    => $this->slideshowTransitionDuration,
            'folien'               => $slides,
        ];

        if ($this->slideshowTransitions !== []) {
            $payload['uebergaenge'] = $this->slideshowTransitions;
        }

        if ($this->slideshowMusic !== null) {
            $payload['musik'] = $this->slideshowMusic;
        }

        if ($title !== '') {
            $payload['titel'] = $title;
        }

        if ($description !== '') {
            $payload['beschreibung'] = $description;
        }

        return $payload;
    }

    private function resolveStoryboardTransition(?string $transition): ?string
    {
        if (!is_string($transition) || $transition === '') {
            return null;
        }

        $trimmed = trim($transition);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function buildThumbnailUrl(int $mediaId, int $width, string $baseUrl): string
    {
        $path = sprintf('/api/media/%d/thumbnail?breite=%d', $mediaId, $width);

        return $baseUrl !== '' ? $baseUrl . $path : $path;
    }

    private function resolveLightboxWidth(): int
    {
        $width = max($this->defaultMemberWidth, $this->defaultLightboxWidth);

        return min($width, $this->maxThumbnailWidth);
    }

    /**
     * @param array<string, scalar|array|null> $clusterParams
     *
     * @return array<string, mixed>
     */
    private function buildGalleryEntry(
        int $mediaId,
        ?Media $media,
        string $baseUrl,
        DateTimeImmutable $reference,
        array $clusterParams,
    ): array {
        $entry = [
            'mediaId'           => $mediaId,
            'thumbnail'         => $this->buildThumbnailUrl($mediaId, $this->defaultMemberWidth, $baseUrl),
            'lightbox'          => $this->buildThumbnailUrl($mediaId, $this->resolveLightboxWidth(), $baseUrl),
            'aufgenommenAm'     => $this->formatTakenAt($media),
            'aufgenommenAmText' => $this->formatMediaDateText($media),
            'hinweisAufgenommenAm' => $this->formatRelativeTakenAt($media, $reference),
            'abmessungen'       => $this->extractDimensions($media),
        ];

        $context = $this->buildMediaContext($media, $clusterParams);
        if ($context['personen'] !== []) {
            $entry['personen'] = $context['personen'];
        }

        if ($context['schlagwoerter'] !== []) {
            $entry['schlagwoerter'] = $context['schlagwoerter'];
        }

        if ($context['szenen'] !== []) {
            $entry['szenen'] = $context['szenen'];
        }

        if ($context['ort'] !== null) {
            $entry['ort'] = $context['ort'];
        }

        if ($context['beschreibung'] !== null) {
            $entry['beschreibung'] = $context['beschreibung'];
        }

        $altText = $this->generateAltText($media, $context);
        if ($altText !== null) {
            $entry['altText'] = $altText;
        }

        return $entry;
    }

    /**
     * @param array{
     *     personen: list<string>,
     *     schlagwoerter: list<string>,
     *     szenen: list<string>,
     *     ort: ?string,
     *     beschreibung: ?string
     * } $context
     */
    private function generateAltText(?Media $media, array $context): ?string
    {
        $segments = [];
        $typeLabel = $media instanceof Media && $media->isVideo() ? 'Video' : 'Foto';
        $segments[] = $typeLabel;

        $description = $context['beschreibung'];
        if (is_string($description) && $description !== '') {
            $segments[] = $description;
        } else {
            $details = [];

            $location = $context['ort'];
            if (is_string($location) && $location !== '') {
                $details[] = 'in ' . $location;
            }

            if ($context['personen'] !== []) {
                $details[] = 'mit ' . implode(', ', $context['personen']);
            }

            if ($context['szenen'] !== []) {
                $details[] = implode(', ', $context['szenen']);
            }

            if ($context['schlagwoerter'] !== []) {
                $details[] = implode(', ', $context['schlagwoerter']);
            }

            if ($details !== []) {
                $segments[] = implode(' • ', $details);
            }
        }

        $dateText = $this->formatMediaDateText($media);
        if ($dateText !== null) {
            $segments[] = 'aufgenommen am ' . $dateText;
        }

        $segments = array_values(array_filter(
            $segments,
            static fn (string $value): bool => trim($value) !== '',
        ));

        if ($segments === []) {
            return null;
        }

        $text = implode('. ', $segments);
        if (!str_ends_with($text, '.')) {
            $text .= '.';
        }

        return $text;
    }

    /**
     * @param array<string, scalar|array|null> $clusterParams
     *
     * @return array{
     *     personen: list<string>,
     *     schlagwoerter: list<string>,
     *     szenen: list<string>,
     *     ort: ?string,
     *     beschreibung: ?string
     * }
     */
    private function buildMediaContext(?Media $media, array $clusterParams): array
    {
        $persons = $this->uniqueStringList($media instanceof Media ? $media->getPersons() : null);

        $keywords = $this->uniqueStringList($media instanceof Media ? $media->getKeywords() : null);

        if ($keywords === []) {
            $keywords = $this->uniqueStringList($clusterParams['keywords'] ?? null);
        }

        $sceneTags = $this->extractSceneTagLabels($media instanceof Media ? $media->getSceneTags() : null, 5);

        if ($sceneTags === []) {
            $sceneTags = $this->extractSceneTagLabels($clusterParams['scene_tags'] ?? null, 5);
        }

        $location = null;
        if ($media instanceof Media) {
            $loc = $media->getLocation();
            if ($loc !== null) {
                $label = trim($loc->getDisplayName());
                if ($label !== '') {
                    $location = $label;
                }
            }
        }

        if ($location === null) {
            $place = $clusterParams['place'] ?? null;
            if (is_string($place)) {
                $trimmed = trim($place);
                if ($trimmed !== '') {
                    $location = $trimmed;
                }
            }
        }

        $descriptionParts = [];
        if ($location !== null) {
            $descriptionParts[] = $location;
        }

        if ($persons !== []) {
            $descriptionParts[] = 'Mit ' . implode(', ', $persons);
        }

        if ($sceneTags !== []) {
            $descriptionParts[] = 'Szenen: ' . implode(', ', $sceneTags);
        }

        if ($keywords !== []) {
            $descriptionParts[] = 'Tags: ' . implode(', ', $keywords);
        }

        $description = $descriptionParts !== [] ? implode(' • ', $descriptionParts) : null;

        return [
            'personen'      => $persons,
            'schlagwoerter' => $keywords,
            'szenen'        => $sceneTags,
            'ort'           => $location,
            'beschreibung'  => $description,
        ];
    }

    /**
     * @return list<string>
     */
    private function uniqueStringList(mixed $values, int $limit = PHP_INT_MAX): array
    {
        if (!is_iterable($values)) {
            return [];
        }

        $result = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '' || in_array($trimmed, $result, true)) {
                continue;
            }

            $result[] = $trimmed;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function extractSceneTagLabels(mixed $tags, int $limit): array
    {
        if (!is_iterable($tags)) {
            return [];
        }

        $labels = [];

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $label = $tag['label'] ?? null;
            if (!is_string($label)) {
                continue;
            }

            $trimmed = trim($label);
            if ($trimmed === '' || in_array($trimmed, $labels, true)) {
                continue;
            }

            $labels[] = $trimmed;

            if (count($labels) >= $limit) {
                break;
            }
        }

        return $labels;
    }


    private function createItemId(MemoryFeedItem $item): string
    {
        $members = $item->getMemberIds();

        return hash('sha1', $item->getAlgorithm() . '|' . implode(',', $members));
    }

    private function extractGroup(MemoryFeedItem $item): ?string
    {
        $params = $item->getParams();
        if (!array_key_exists('group', $params)) {
            return null;
        }

        $group = $params['group'];
        if (!is_string($group)) {
            return null;
        }

        if ($group === '') {
            return null;
        }

        return $group;
    }

    private function extractTimeRange(MemoryFeedItem $item, DateTimeImmutable $reference): ?array
    {
        $params = $item->getParams();
        $range  = $params['time_range'] ?? null;
        if (!is_array($range)) {
            return null;
        }

        $fromDate = null;
        $toDate   = null;

        $from = $range['from'] ?? null;
        if (is_numeric($from)) {
            $fromDate = $this->timestampToDate((int) $from);
        }

        $to = $range['to'] ?? null;
        if (is_numeric($to)) {
            $toDate = $this->timestampToDate((int) $to);
        }

        if ($fromDate === null && $toDate === null) {
            return null;
        }

        $result = [];

        if ($fromDate !== null) {
            $result['von']        = $fromDate->format(DateTimeInterface::ATOM);
            $result['vonText']    = $this->formatDateOnly($fromDate);
            $result['hinweisVon'] = $this->formatRelativeTime($fromDate, $reference);
        }

        if ($toDate !== null) {
            $result['bis']        = $toDate->format(DateTimeInterface::ATOM);
            $result['bisText']    = $this->formatDateOnly($toDate);
            $result['hinweisBis'] = $this->formatRelativeTime($toDate, $reference);
        }

        $result['beschreibung'] = $this->describeTimeRange($fromDate ?? $toDate, $toDate);

        return $result;
    }

    private function extractDimensions(?Media $media): ?array
    {
        if (!$media instanceof Media) {
            return null;
        }

        $width  = $media->getWidth();
        $height = $media->getHeight();

        if ($width === null || $height === null) {
            return null;
        }

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $ratio       = round($width / $height, 2);
        $orientation = $width >= $height ? 'querformat' : 'hochformat';

        return [
            'breite'            => $width,
            'hoehe'             => $height,
            'seitenverhaeltnis' => $ratio,
            'ausrichtung'       => $orientation,
        ];
    }

    private function formatMediaDateText(?Media $media): ?string
    {
        if (!$media instanceof Media) {
            return null;
        }

        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $this->formatLocalizedDate($takenAt);
    }

    private function formatRelativeTakenAt(?Media $media, DateTimeImmutable $reference): ?string
    {
        if (!$media instanceof Media) {
            return null;
        }

        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $this->formatRelativeTime($takenAt, $reference);
    }

    /**
     * @param array<string, scalar|array|null> $params
     *
     * @return array<string, mixed>
     */
    private function buildClusterContext(array $params): array
    {
        $context = [];

        $placeParts = [];
        foreach (['place', 'place_city', 'place_region', 'place_country'] as $key) {
            $value = $params[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            if (!in_array($trimmed, $placeParts, true)) {
                $placeParts[] = $trimmed;
            }
        }

        if ($placeParts !== []) {
            $context['orte'] = $placeParts;
        }

        $poiLabel = $params['poi_label'] ?? null;
        if (is_string($poiLabel)) {
            $trimmed = trim($poiLabel);
            if ($trimmed !== '') {
                $context['poi'] = $trimmed;
            }
        }

        if (isset($params['scene_tags']) && is_array($params['scene_tags'])) {
            $labels = [];
            foreach ($params['scene_tags'] as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $label = $tag['label'] ?? null;
                if (!is_string($label)) {
                    continue;
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    continue;
                }

                $labels[] = $trimmed;
            }

            if ($labels !== []) {
                $context['szenen'] = $labels;
            }
        }

        if (isset($params['keywords']) && is_array($params['keywords'])) {
            $keywords = [];
            foreach ($params['keywords'] as $keyword) {
                if (!is_string($keyword)) {
                    continue;
                }

                $trimmed = trim($keyword);
                if ($trimmed === '') {
                    continue;
                }

                $keywords[] = $trimmed;
            }

            if ($keywords !== []) {
                $context['schlagwoerter'] = $keywords;
            }
        }

        $peopleCount  = $params['people_count'] ?? null;
        $uniquePeople = $params['people_unique'] ?? null;
        if (is_numeric($peopleCount) && is_numeric($uniquePeople)) {
            $context['personenHinweis'] = sprintf(
                '%d Personen in %d Aufnahmen',
                (int) $uniquePeople,
                (int) $peopleCount,
            );
        }

        return $context;
    }

    private function enrichSlideshowStatus(SlideshowVideoStatus $status): array
    {
        $payload = $status->toArray();
        $payload['hinweis'] = $payload['meldung'];
        $payload['fortschritt'] = $status->status() === SlideshowVideoStatus::STATUS_READY ? 1.0 : 0.0;

        return $payload;
    }

    private function timestampToDate(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Berlin'));
    }

    private function describeTimeRange(?DateTimeImmutable $from, ?DateTimeImmutable $to): string
    {
        if ($from === null && $to === null) {
            return '';
        }

        if ($from === null && $to !== null) {
            return 'am ' . $this->formatDateOnly($to);
        }

        if ($from === null) {
            return '';
        }

        if ($to === null || $from->format('Y-m-d') === $to->format('Y-m-d')) {
            return 'am ' . $this->formatDateOnly($from);
        }

        if ($from->format('Y-m') === $to->format('Y-m')) {
            return 'im ' . $this->formatMonthName($from) . ' ' . $from->format('Y');
        }

        if ($from->format('Y') === $to->format('Y')) {
            $season = $this->determineSeason($from, $to);
            if ($season !== null) {
                return $season . ' ' . $from->format('Y');
            }

            return 'im Jahr ' . $from->format('Y');
        }

        return sprintf(
            'zwischen %s und %s',
            $this->formatDateOnly($from),
            $this->formatDateOnly($to),
        );
    }

    private function formatMonthName(DateTimeImmutable $date): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            try {
                $formatter = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, $date->getTimezone()->getName(), null, 'LLLL');
                $formatted = $formatter->format($date);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (IntlException) {
                // Fallback handled below.
            }
        }

        return mb_convert_case($date->format('F'), MB_CASE_TITLE, 'UTF-8');
    }

    private function determineSeason(DateTimeImmutable $from, DateTimeImmutable $to): ?string
    {
        $startMonth = (int) $from->format('n');
        $endMonth   = (int) $to->format('n');

        if ($startMonth === $endMonth) {
            return $this->seasonName($startMonth);
        }

        if ($startMonth <= 2 && $endMonth <= 2) {
            return 'Winter';
        }

        if ($startMonth >= 12 || $endMonth >= 12) {
            return 'Winter';
        }

        if ($startMonth >= 3 && $endMonth <= 5) {
            return 'Frühling';
        }

        if ($startMonth >= 6 && $endMonth <= 8) {
            return 'Sommer';
        }

        if ($startMonth >= 9 && $endMonth <= 11) {
            return 'Herbst';
        }

        return null;
    }

    private function seasonName(int $month): string
    {
        if ($month >= 3 && $month <= 5) {
            return 'Frühling';
        }

        if ($month >= 6 && $month <= 8) {
            return 'Sommer';
        }

        if ($month >= 9 && $month <= 11) {
            return 'Herbst';
        }

        return 'Winter';
    }

    private function formatLocalizedDate(DateTimeImmutable $date): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            try {
                $formatter = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::SHORT, $date->getTimezone()->getName());
                $formatted = $formatter->format($date);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (IntlException) {
                // Fallback handled below.
            }
        }

        return $date->format('d.m.Y H:i');
    }

    private function formatDateOnly(DateTimeImmutable $date): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            try {
                $formatter = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, $date->getTimezone()->getName());
                $formatted = $formatter->format($date);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (IntlException) {
                // Fallback handled below.
            }
        }

        return $date->format('d.m.Y');
    }

    private function formatRelativeTime(DateTimeImmutable $date, DateTimeImmutable $reference): string
    {
        $diffSeconds = $reference->getTimestamp() - $date->getTimestamp();

        if ($diffSeconds <= 45) {
            return 'gerade eben';
        }

        if ($diffSeconds <= 90) {
            return 'vor einer Minute';
        }

        if ($diffSeconds <= 2700) {
            $minutes = (int) round($diffSeconds / 60);

            return 'vor ' . (string) max($minutes, 2) . ' Minuten';
        }

        if ($diffSeconds <= 5400) {
            return 'vor einer Stunde';
        }

        if ($diffSeconds <= 86400) {
            $hours = (int) round($diffSeconds / 3600);

            return 'vor ' . (string) max($hours, 2) . ' Stunden';
        }

        if ($diffSeconds <= 172800) {
            return 'gestern';
        }

        if ($diffSeconds <= 604800) {
            $days = (int) round($diffSeconds / 86400);

            return 'vor ' . (string) max($days, 2) . ' Tagen';
        }

        if ($diffSeconds <= 2419200) {
            $weeks = (int) round($diffSeconds / 604800);
            if ($weeks <= 1) {
                return 'vor einer Woche';
            }

            return 'vor ' . (string) $weeks . ' Wochen';
        }

        if ($diffSeconds <= 29030400) {
            $months = (int) round($diffSeconds / 2419200);
            if ($months <= 1) {
                return 'vor einem Monat';
            }

            return 'vor ' . (string) $months . ' Monaten';
        }

        $years = (int) round($diffSeconds / 29030400);
        if ($years <= 1) {
            return 'vor einem Jahr';
        }

        return 'vor ' . (string) $years . ' Jahren';
    }

    private function buildLabelMapping(): array
    {
        return [
            'algorithmus' => 'Strategie',
            'gruppe'      => 'Gruppe',
            'titel'       => 'Titel',
            'untertitel'  => 'Untertitel',
            'score'       => 'Relevanzwert',
            'zeitspanne'  => 'Zeitraum',
            'galerie'     => 'Galerie',
            'slideshow'   => 'Slideshow-Status',
            'kontext'     => 'Zusatzinformationen',
        ];
    }

    /**
     * @param list<MemoryFeedItem> $items
     */
    private function createCursor(array $items): ?string
    {
        $count = count($items);
        if ($count === 0) {
            return null;
        }

        $last = $items[$count - 1];
        $range = $last->getParams()['time_range'] ?? null;
        if (is_array($range) && isset($range['from']) && is_numeric($range['from'])) {
            return 'time:' . (string) $range['from'];
        }

        $members = $last->getMemberIds();
        $memberCount = count($members);
        if ($memberCount > 0) {
            return 'media:' . (string) $members[$memberCount - 1];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $meta
     */
    private function buildFuerDichComponent(array $items, array $meta): array
    {
        return [
            'items'                => $items,
            'pagination'           => $meta['pagination'] ?? [],
            'filter'               => $meta['filter'] ?? [],
            'filterKonfiguration'  => $meta['filterKonfiguration'] ?? [],
            'personalisierung'     => $meta['personalisierung'] ?? [],
            'animationen'          => $this->spaAnimationConfig['feed'] ?? [],
            'gesten'               => $this->spaGestureConfig['feed'] ?? [],
        ];
    }

    /**
     * @param list<MemoryFeedItem> $items
     */
    private function buildTimelineComponent(array $items, string $locale): array
    {
        $groups = [];

        foreach ($items as $item) {
            $date = $this->resolveTimelineDate($item);
            if (!$date instanceof DateTimeImmutable) {
                continue;
            }

            $key = $date->format('Y-m');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'monat'     => (int) $date->format('n'),
                    'jahr'      => (int) $date->format('Y'),
                    'titel'     => $this->formatMonthLabel($date, $locale),
                    'eintraege' => [],
                ];
            }

            $groups[$key]['eintraege'][] = [
                'id'          => $this->createItemId($item),
                'titel'       => $item->getTitle(),
                'untertitel'  => $item->getSubtitle(),
                'algorithmus' => $item->getAlgorithm(),
                'score'       => $item->getScore(),
                'zeitstempel' => $date->format(DateTimeInterface::ATOM),
            ];
        }

        if ($groups === []) {
            return [
                'gruppen'    => [],
                'gesten'     => $this->spaGestureConfig['timeline'] ?? [],
                'animationen'=> $this->spaAnimationConfig['timeline'] ?? [],
            ];
        }

        krsort($groups);
        $groups = array_slice($groups, 0, $this->spaTimelineMonths, true);

        foreach ($groups as &$group) {
            $group['anzahl'] = count($group['eintraege']);
        }

        return [
            'gruppen'    => array_values($groups),
            'gesten'     => $this->spaGestureConfig['timeline'] ?? [],
            'animationen'=> $this->spaAnimationConfig['timeline'] ?? [],
        ];
    }

    private function resolveTimelineDate(MemoryFeedItem $item): ?DateTimeImmutable
    {
        $params = $item->getParams();
        $range  = $params['time_range'] ?? null;

        if (is_array($range)) {
            $from = $range['from'] ?? null;
            if (is_numeric($from)) {
                return $this->timestampToDate((int) $from);
            }

            $to = $range['to'] ?? null;
            if (is_numeric($to)) {
                return $this->timestampToDate((int) $to);
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function buildStoryViewerComponent(array $items): array
    {
        $stories = [];

        foreach ($items as $item) {
            $storyboard = $item['storyboard'] ?? null;
            if (!is_array($storyboard)) {
                continue;
            }

            $slides = $storyboard['folien'] ?? null;
            if (!is_array($slides) || $slides === []) {
                continue;
            }

            $stories[] = [
                'id'                => $item['id'] ?? null,
                'titel'             => $storyboard['titel'] ?? ($item['titel'] ?? null),
                'beschreibung'      => $storyboard['beschreibung'] ?? ($item['untertitel'] ?? null),
                'folien'            => $slides,
                'dauerSekunden'     => $storyboard['dauerSekunden'] ?? null,
                'uebergangSekunden' => $storyboard['uebergangSekunden'] ?? null,
            ];
        }

        return [
            'stories'    => $stories,
            'gesten'     => $this->spaGestureConfig['story_viewer'] ?? [],
            'animationen'=> $this->mergeStoryViewerAnimations(),
        ];
    }

    private function mergeStoryViewerAnimations(): array
    {
        $animations = [
            'bildMs'      => (int) round($this->slideshowImageDuration * 1000),
            'uebergangMs' => (int) round($this->slideshowTransitionDuration * 1000),
        ];

        $config = $this->spaAnimationConfig['story_viewer'] ?? [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || !is_numeric($value)) {
                continue;
            }

            $animations[$key] = (int) round((float) $value);
        }

        return $animations;
    }

    private function buildOfflineComponent(DateTimeImmutable $reference, FeedUserPreferences $preferences): array
    {
        $serviceWorker = [
            'pfad'      => $this->spaOfflineConfig['service_worker'] ?? '/app/service-worker.js',
            'scope'     => $this->spaOfflineConfig['scope'] ?? '/',
            'precache'  => $this->spaOfflineConfig['precache'] ?? ['/api/feed'],
        ];

        if (isset($this->spaOfflineConfig['runtime'])) {
            $serviceWorker['runtimeCaching'] = $this->spaOfflineConfig['runtime'];
        }

        if (isset($this->spaOfflineConfig['fallback'])) {
            $serviceWorker['fallbackRoute'] = $this->spaOfflineConfig['fallback'];
        }

        return [
            'serviceWorker'       => $serviceWorker,
            'gesten'              => $this->spaGestureConfig,
            'animationen'         => $this->spaAnimationConfig,
            'favoriten'           => $preferences->getFavourites(),
            'precacheItems'       => $preferences->getFavourites(),
            'letzteAktualisierung'=> $reference->format(DateTimeInterface::ATOM),
        ];
    }

    private function formatMonthLabel(DateTimeImmutable $date, string $locale): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            try {
                $formatter = new IntlDateFormatter(
                    $locale,
                    IntlDateFormatter::LONG,
                    IntlDateFormatter::NONE,
                    $date->getTimezone()->getName(),
                    null,
                    'LLLL yyyy',
                );

                $formatted = $formatter->format($date);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (IntlException) {
                // Fallback handled below.
            }
        }

        return $this->formatMonthName($date) . ' ' . $date->format('Y');
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<string, array<string, string>>
     */
    private function sanitizeGestureConfig(array $config): array
    {
        $result = [];

        foreach ($config as $section => $gestures) {
            if (!is_string($section) || !is_array($gestures)) {
                continue;
            }

            $entries = [];
            foreach ($gestures as $name => $value) {
                if (!is_string($name) || !is_string($value)) {
                    continue;
                }

                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                $entries[$name] = $trimmed;
            }

            if ($entries !== []) {
                $result[$section] = $entries;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function sanitizeOfflineConfig(array $config): array
    {
        $result = [];

        $serviceWorker = $config['service_worker'] ?? null;
        if (is_string($serviceWorker)) {
            $trimmed = trim($serviceWorker);
            if ($trimmed !== '') {
                $result['service_worker'] = $trimmed;
            }
        }

        $scope = $config['scope'] ?? null;
        if (is_string($scope)) {
            $trimmed = trim($scope);
            if ($trimmed !== '') {
                $result['scope'] = $trimmed;
            }
        }

        $precache = $config['precache'] ?? null;
        if (is_array($precache)) {
            $list = $this->sanitizeStringList($precache);
            if ($list !== []) {
                $result['precache'] = $list;
            }
        }

        $runtime = $config['runtime'] ?? null;
        if (is_array($runtime)) {
            $entries = [];
            foreach ($runtime as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $pattern  = $entry['pattern'] ?? null;
                $strategy = $entry['strategy'] ?? null;
                if (!is_string($pattern) || !is_string($strategy)) {
                    continue;
                }

                $patternTrim  = trim($pattern);
                $strategyTrim = trim($strategy);
                if ($patternTrim === '' || $strategyTrim === '') {
                    continue;
                }

                $entries[] = [
                    'pattern'  => $patternTrim,
                    'strategy' => $strategyTrim,
                ];
            }

            if ($entries !== []) {
                $result['runtime'] = $entries;
            }
        }

        $fallback = $config['fallback'] ?? null;
        if (is_string($fallback)) {
            $trimmed = trim($fallback);
            if ($trimmed !== '') {
                $result['fallback'] = $trimmed;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function sanitizeAnimationConfig(array $config): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->sanitizeAnimationConfig($value);
                if ($nested !== []) {
                    $result[$key] = $nested;
                }

                continue;
            }

            if (is_numeric($value)) {
                $result[$key] = (int) round((float) $value);
            }
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return list<string>
     */
    private function sanitizeStringList(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $result[] = $trimmed;
        }

        return $result;
    }

    /**
     * @param list<MemoryFeedItem> $items
     *
     * @return list<string>
     */
    private function collectStrategies(array $items): array
    {
        $strategies = [];
        foreach ($items as $item) {
            $strategies[$item->getAlgorithm()] = true;
        }

        $result = array_keys($strategies);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param list<MemoryFeedItem> $items
     *
     * @return list<string>
     */
    private function collectGroups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $group = $this->extractGroup($item);
            if ($group === null) {
                continue;
            }

            $groups[$group] = true;
        }

        $result = array_keys($groups);
        sort($result, SORT_STRING);

        return $result;
    }

    private function matchesDate(MemoryFeedItem $item, DateTimeImmutable $target): bool
    {
        $params = $item->getParams();
        $range  = $params['time_range'] ?? null;
        if (!is_array($range)) {
            return false;
        }

        $from = $range['from'] ?? null;
        $to   = $range['to'] ?? null;

        if (!is_numeric($from) && !is_numeric($to)) {
            return false;
        }

        $timezone = new DateTimeZone('Europe/Berlin');

        $start = $from !== null ? (new DateTimeImmutable('@' . $from))->setTimezone($timezone) : null;
        $end   = $to !== null ? (new DateTimeImmutable('@' . $to))->setTimezone($timezone) : null;

        $dayStart = $target->setTime(0, 0);
        $dayEnd   = $dayStart->add(new DateInterval('P1D'));

        if ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable) {
            return $start < $dayEnd && $end >= $dayStart;
        }

        if ($start instanceof DateTimeImmutable) {
            return $start >= $dayStart && $start < $dayEnd;
        }

        if ($end instanceof DateTimeImmutable) {
            return $end >= $dayStart && $end < $dayEnd;
        }

        return false;
    }

    public function slideshow(string $itemId): JsonResponse|BinaryFileResponse
    {
        $path = $this->slideshowManager->resolveVideoPath($itemId);
        if ($path === null) {
            return new JsonResponse([
                'error' => 'Slideshow video not available.',
            ], 404);
        }

        return new BinaryFileResponse($path);
    }
}
