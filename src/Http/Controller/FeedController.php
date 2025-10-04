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
use MagicSunday\Memories\Http\Request;
use MagicSunday\Memories\Http\Response\BinaryFileResponse;
use MagicSunday\Memories\Http\Response\JsonResponse;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoManagerInterface;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Entity\Media;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function array_replace;
use function array_slice;
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

        $availableStrategies = $this->collectStrategies($items);
        $availableGroups     = $this->collectGroups($items);

        $filtered = [];
        foreach ($items as $item) {
            if ($minScore !== null && $item->getScore() < $minScore) {
                continue;
            }

            if ($strategy !== null && $item->getAlgorithm() !== $strategy) {
                continue;
            }

            if ($filterDate !== null && !$this->matchesDate($item, $filterDate)) {
                continue;
            }

            $filtered[] = $item;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        $data = [];
        foreach ($filtered as $item) {
            $data[] = $this->transformItem($item);
        }

        $meta = [
            'erstelltAm'          =>
                (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'gesamtVerfuegbar'    => count($items),
            'anzahlGeliefert'     => count($data),
            'verfuegbareStrategien' => $availableStrategies,
            'verfuegbareGruppen'    => $availableGroups,
            'filter'              => [
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

        $result = [];
        foreach ($ids as $id) {
            $media = $this->mediaCache[$id] ?? null;
            if ($media instanceof Media) {
                $result[$id] = $media;
            }
        }

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

    private function transformItem(MemoryFeedItem $item): array
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

            $memberPayload[] = [
                'mediaId'        => $memberId,
                'thumbnail'      => $this->buildThumbnailUrl($memberId, $this->defaultMemberWidth),
                'aufgenommenAm'  => $this->formatTakenAt($media),
            ];
        }

        $coverMedia = $coverId !== null ? ($memberMediaMap[$coverId] ?? null) : null;

        $itemId = $this->createItemId($item);
        $status = $this->slideshowManager->ensureForItem($itemId, $previewMembers, $memberMediaMap);

        return [
            'id'            => $itemId,
            'algorithmus'   => $item->getAlgorithm(),
            'gruppe'        => $this->extractGroup($item),
            'titel'         => $item->getTitle(),
            'untertitel'    => $item->getSubtitle(),
            'score'         => $item->getScore(),
            'coverMediaId'  => $coverId,
            'cover'         => $coverId !== null ? $this->buildThumbnailUrl($coverId, $this->defaultCoverWidth) : null,
            'coverAufgenommenAm' => $this->formatTakenAt($coverMedia),
            'mitglieder'    => $previewMembers,
            'galerie'       => $memberPayload,
            'zeitspanne'    => $this->extractTimeRange($item),
            'zusatzdaten'   => $item->getParams(),
            'slideshow'     => $status->toArray(),
        ];
    }

    private function buildThumbnailUrl(int $mediaId, int $width): string
    {
        return sprintf('/api/media/%d/thumbnail?breite=%d', $mediaId, $width);
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

    private function extractTimeRange(MemoryFeedItem $item): ?array
    {
        $params = $item->getParams();
        $range  = $params['time_range'] ?? null;
        if (!is_array($range)) {
            return null;
        }

        $from = $range['from'] ?? null;
        $to   = $range['to'] ?? null;

        $result = [];
        if (is_numeric($from)) {
            $fromDate = (new DateTimeImmutable('@' . (string) $from))->setTimezone(new DateTimeZone('Europe/Berlin'));
            $result['von'] = $fromDate->format(DateTimeInterface::ATOM);
        }

        if (is_numeric($to)) {
            $toDate = (new DateTimeImmutable('@' . (string) $to))->setTimezone(new DateTimeZone('Europe/Berlin'));
            $result['bis'] = $toDate->format(DateTimeInterface::ATOM);
        }

        if ($result === []) {
            return null;
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

        $start = $from !== null ? (new DateTimeImmutable('@' . (string) $from))->setTimezone($timezone) : null;
        $end   = $to !== null ? (new DateTimeImmutable('@' . (string) $to))->setTimezone($timezone) : null;

        $dayStart = $target->setTime(0, 0, 0);
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
