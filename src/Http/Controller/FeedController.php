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
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoManagerInterface;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoStatus;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use RuntimeException;
use IntlDateFormatter;

use function array_fill_keys;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_replace;
use function array_slice;
use function array_values;
use function count;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sort;
use function sprintf;
use function trim;
use function mb_convert_case;

use const SORT_STRING;

/**
 * HTTP controller to expose the Rückblick feed and thumbnail media.
 */
final class FeedController
{
    /**
     * @var array<int, Media|null>
     */
    private array $mediaCache = [];

    /**
     * User friendly labels for well-known feed strategies.
     */
    private const array STRATEGY_LABELS = [
        'monthly_highlights'           => 'Monatshighlights',
        'video_stories'                => 'Videogeschichten',
        'time_similarity'              => 'Zeitlich nahe Erinnerungen',
        'significant_place'            => 'Besondere Orte',
        'device_similarity'            => 'Geräte-Schwerpunkte',
        'portrait_orientation'         => 'Porträtmomente',
        'transit_travel_day'           => 'Reisetage',
        'nightlife_event'              => 'Abende und Nachtleben',
        'golden_hour'                  => 'Goldene Stunde',
        'person_cohort'                => 'Personen-Gruppen',
        'day_album'                    => 'Tagesalbum',
        'significant_place_highlights' => 'Highlights am Lieblingsort',
    ];

    public function __construct(
        private readonly FeedBuilderInterface $feedBuilder,
        private readonly ClusterRepository $clusterRepository,
        private readonly ClusterEntityToDraftMapper $clusterMapper,
        private readonly ThumbnailPathResolver $thumbnailResolver,
        private readonly MediaRepository $mediaRepository,
        private readonly ThumbnailServiceInterface $thumbnailService,
        private readonly SlideshowVideoManagerInterface $slideshowManager,
        private readonly EntityManagerInterface $entityManager,
        private int $defaultFeedLimit = 24,
        private int $maxFeedLimit = 120,
        private int $previewImageCount = 8,
        private int $clusterFetchMultiplier = 4,
        private int $defaultCoverWidth = 640,
        private int $defaultMemberWidth = 320,
        private int $maxThumbnailWidth = 2048,
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

        if ($this->maxThumbnailWidth < $this->defaultCoverWidth) {
            $this->maxThumbnailWidth = $this->defaultCoverWidth;
        }
    }

    public function feed(Request $request): JsonResponse
    {
        $limit        = $this->normalizeLimit($request->getQueryParam('limit'));
        $minScore     = $this->normalizeFloat($request->getQueryParam('score'));
        $strategy     = $this->normalizeString($request->getQueryParam('strategie'));
        $dateParam    = $this->normalizeString($request->getQueryParam('datum'));
        $clusterLimit = max($limit * $this->clusterFetchMultiplier, $limit);

        $filterDate = null;
        if ($dateParam !== null) {
            $filterDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateParam);
            $errors     = DateTimeImmutable::getLastErrors();

            $hasErrors = ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0;
            if (!$filterDate instanceof DateTimeImmutable || $hasErrors) {
                return new JsonResponse([
                    'error' => 'Invalid date filter format, expected YYYY-MM-DD.',
                ], 400);
            }
        }

        $clusters = $this->clusterRepository->findLatest($clusterLimit);
        $drafts   = $this->clusterMapper->mapMany($clusters);
        $items    = $this->feedBuilder->build($drafts);

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

        $matchingItems = $filtered;
        $matchingCount = count($matchingItems);
        $pagedItems    = array_slice($matchingItems, 0, $limit);

        $availableStrategies = $this->collectStrategies($matchingItems);
        $availableGroups     = $this->collectGroups($matchingItems);

        $now = new DateTimeImmutable();
        $host = $request->getSchemeAndHttpHost();
        $baseUrl = $host !== '' ? rtrim($host, '/') : '';

        /** @var list<array<string, mixed>> $data */
        $data = array_map(
            fn (MemoryFeedItem $item): array => $this->transformItem($item, $baseUrl, $now),
            $pagedItems,
        );

        $hasMore   = count($data) < $matchingCount;
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
                'hatWeitere'   => $hasMore,
                'nextCursor'   => $hasMore ? $this->createCursor($pagedItems) : null,
                'limitEmpfehlung' => $this->defaultFeedLimit,
            ],
            'filter'                => [
                'score'     => $minScore,
                'strategie' => $strategy,
                'datum'     => $filterDate?->format('Y-m-d'),
                'limit'     => $limit,
            ],
        ];

        return new JsonResponse([
            'meta'  => $meta,
            'items' => $data,
        ]);
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

    private function resolveOrGenerateThumbnail(Media $media, int $width): ?string
    {
        $resolved = $this->thumbnailResolver->resolveBest($media, $width);
        if (is_string($resolved)) {
            if ($resolved !== $media->getPath()) {
                return $resolved;
            }

            $existing = $media->getThumbnails();

            if (is_array($existing) && $existing !== []) {
                return $resolved;
            }
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

    private function transformItem(MemoryFeedItem $item, string $baseUrl, DateTimeImmutable $reference): array
    {
        $coverId = $item->getCoverMediaId();
        $members = $item->getMemberIds();

        $previewMembers = array_slice($members, 0, $this->previewImageCount);
        $mediaIdsToLoad = $previewMembers;
        if ($coverId !== null && !in_array($coverId, $mediaIdsToLoad, true)) {
            $mediaIdsToLoad[] = $coverId;
        }

        $onlyVideos     = $item->getAlgorithm() === 'video_stories';
        $memberPayload  = [];
        $memberMediaMap = $this->loadMediaMap($mediaIdsToLoad, $onlyVideos);
        foreach ($previewMembers as $memberId) {
            $media = $memberMediaMap[$memberId] ?? null;

            $memberPayload[] = $this->buildGalleryEntry(
                $memberId,
                $media,
                $baseUrl,
                $reference,
                $item->getParams(),
            );
        }

        $coverMedia = $coverId !== null ? ($memberMediaMap[$coverId] ?? null) : null;

        $itemId = $this->createItemId($item);
        $status = $this->slideshowManager->ensureForItem($itemId, $previewMembers, $memberMediaMap);

        $clusterContext = $this->buildClusterContext($item->getParams());
        $timeRange      = $this->extractTimeRange($item, $reference);

        return [
            'id'                 => $itemId,
            'algorithmus'        => $item->getAlgorithm(),
            'algorithmusLabel'   => $this->translateAlgorithm($item->getAlgorithm()),
            'gruppe'             => $this->extractGroup($item),
            'titel'              => $item->getTitle(),
            'untertitel'         => $item->getSubtitle(),
            'score'              => $item->getScore(),
            'coverMediaId'       => $coverId,
            'cover'              => $coverId !== null ? $this->buildThumbnailUrl($coverId, $this->defaultCoverWidth, $baseUrl) : null,
            'coverAufgenommenAm' => $this->formatTakenAt($coverMedia),
            'coverAufgenommenAmText' => $this->formatMediaDateText($coverMedia),
            'coverHinweisAufgenommenAm' => $this->formatRelativeTakenAt($coverMedia, $reference),
            'coverAbmessungen'   => $this->extractDimensions($coverMedia),
            'mitglieder'         => $previewMembers,
            'galerie'            => $memberPayload,
            'zeitspanne'         => $timeRange,
            'zusatzdaten'        => $item->getParams(),
            'kontext'            => $clusterContext,
            'slideshow'          => $this->enrichSlideshowStatus($status),
        ];
    }

    private function buildThumbnailUrl(int $mediaId, int $width, string $baseUrl): string
    {
        $path = sprintf('/api/media/%d/thumbnail?breite=%d', $mediaId, $width);

        return $baseUrl !== '' ? $baseUrl . $path : $path;
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

        return $entry;
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
        $persons = [];
        if ($media instanceof Media) {
            $rawPersons = $media->getPersons();
            if (is_array($rawPersons)) {
                foreach ($rawPersons as $person) {
                    if (!is_string($person)) {
                        continue;
                    }

                    $trimmed = trim($person);
                    if ($trimmed === '') {
                        continue;
                    }

                    if (!in_array($trimmed, $persons, true)) {
                        $persons[] = $trimmed;
                    }
                }
            }
        }

        $keywords = [];
        if ($media instanceof Media) {
            $rawKeywords = $media->getKeywords();
            if (is_array($rawKeywords)) {
                foreach ($rawKeywords as $keyword) {
                    if (!is_string($keyword)) {
                        continue;
                    }

                    $trimmed = trim($keyword);
                    if ($trimmed === '') {
                        continue;
                    }

                    if (!in_array($trimmed, $keywords, true)) {
                        $keywords[] = $trimmed;
                    }
                }
            }
        }

        if ($keywords === [] && isset($clusterParams['keywords']) && is_array($clusterParams['keywords'])) {
            foreach ($clusterParams['keywords'] as $keyword) {
                if (!is_string($keyword)) {
                    continue;
                }

                $trimmed = trim($keyword);
                if ($trimmed === '') {
                    continue;
                }

                if (!in_array($trimmed, $keywords, true)) {
                    $keywords[] = $trimmed;
                }
            }
        }

        $sceneTags = [];
        if ($media instanceof Media) {
            $tags = $media->getSceneTags();
            if (is_array($tags)) {
                foreach ($tags as $tag) {
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

                    if (!in_array($trimmed, $sceneTags, true)) {
                        $sceneTags[] = $trimmed;
                    }

                    if (count($sceneTags) >= 5) {
                        break;
                    }
                }
            }
        }

        if ($sceneTags === [] && isset($clusterParams['scene_tags']) && is_array($clusterParams['scene_tags'])) {
            foreach ($clusterParams['scene_tags'] as $tag) {
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

                if (!in_array($trimmed, $sceneTags, true)) {
                    $sceneTags[] = $trimmed;
                }

                if (count($sceneTags) >= 5) {
                    break;
                }
            }
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
            $formatter = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, $date->getTimezone()->getName(), null, 'LLLL');
            $formatted = $formatter->format($date);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
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
            $formatter = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::SHORT, $date->getTimezone()->getName());
            $formatted = $formatter->format($date);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return $date->format('d.m.Y H:i');
    }

    private function formatDateOnly(DateTimeImmutable $date): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter('de_DE', IntlDateFormatter::LONG, IntlDateFormatter::NONE, $date->getTimezone()->getName());
            $formatted = $formatter->format($date);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
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

    private function translateAlgorithm(string $algorithm): string
    {
        $label = self::STRATEGY_LABELS[$algorithm] ?? null;
        if ($label !== null) {
            return $label;
        }

        $normalized = str_replace('_', ' ', $algorithm);
        $normalized = trim($normalized);
        if ($normalized === '') {
            return 'Strategie';
        }

        return mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');
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
