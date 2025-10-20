<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Feed\Contract\FeedExportServiceInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_key_exists;
use function array_slice;
use function array_values;
use function basename;
use function copy;
use function count;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mkdir;
use function number_format;
use function sprintf;
use function str_replace;
use function trim;
use function symlink;
use function ucwords;
use function usort;

use const PHP_INT_MAX;

/**
 * Class HtmlFeedExportService.
 */
final readonly class HtmlFeedExportService implements FeedExportServiceInterface
{
    private const string FEED_TITLE = 'Rückblick – Für dich';

    public function __construct(
        private readonly ClusterRepository $clusters,
        private readonly FeedBuilderInterface $feedBuilder,
        private readonly ClusterConsolidatorInterface $consolidator,
        private readonly ClusterEntityToDraftMapper $mapper,
        private readonly MediaRepository $mediaRepository,
        private readonly HtmlFeedRenderer $renderer,
        private readonly ThumbnailPathResolver $thumbnailResolver,
    ) {
    }

    public function export(FeedExportRequest $request, SymfonyStyle $io): FeedExportResult
    {
        $io->title('📰 HTML-Vorschau des Rückblick-Feeds');

        $outputDirectory = $request->resolveOutputDirectory();
        $imageDirectory  = $request->resolveImageDirectory();

        $this->ensureDirectoryExists($outputDirectory);
        $this->ensureDirectoryExists($imageDirectory);

        $entities = $this->clusters->findLatest($request->getLimitClusters());
        if ($entities === []) {
            $io->warning('Keine Cluster in der Datenbank gefunden.');

            return new FeedExportResult(
                $outputDirectory,
                $imageDirectory,
                null,
                0,
                0,
                0,
                $request->getStage(),
                [
                    FeedExportStage::Raw->value     => 0,
                    FeedExportStage::Merged->value  => 0,
                    FeedExportStage::Curated->value => 0,
                ],
            );
        }

        $rawDrafts    = $this->mapper->mapMany($entities);
        $mergedDrafts = $this->consolidator->consolidate($rawDrafts);
        if ($mergedDrafts === []) {
            $io->warning('Keine Cluster nach der Konsolidierung.');
        }

        $items = $mergedDrafts === [] ? [] : $this->feedBuilder->build($mergedDrafts, null, null);
        if ($items === []) {
            $io->warning('Der Feed ist leer (Filter/Score/Limit zu streng?).');
        }

        if (count($items) > $request->getMaxItems()) {
            $items = array_slice($items, 0, $request->getMaxItems());
        }

        $copiedFileCount   = 0;
        $skippedThumbCount = 0;

        $rawCards    = $this->buildDraftCards($rawDrafts, FeedExportStage::Raw, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);
        $mergedCards = $this->buildDraftCards($mergedDrafts, FeedExportStage::Merged, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);
        $curatedCards = $this->buildCuratedCards($items, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);

        if ($curatedCards === [] && $items !== []) {
            $io->warning('Keine Bilder für die HTML-Ausgabe gefunden.');
        }

        $stages = [
            FeedExportStage::Raw->value => [
                'cards'        => $rawCards,
                'summary'      => $this->formatSummary(count($rawCards), 'Cluster', 'Cluster'),
                'emptyMessage' => 'Keine Cluster in der Datenbank gefunden.',
            ],
            FeedExportStage::Merged->value => [
                'cards'        => $mergedCards,
                'summary'      => $this->formatSummary(count($mergedCards), 'Draft', 'Drafts'),
                'emptyMessage' => 'Keine Cluster nach der Konsolidierung.',
            ],
            FeedExportStage::Curated->value => [
                'cards'        => $curatedCards,
                'summary'      => $this->formatSummary(count($curatedCards), 'Feed-Item', 'Feed-Items'),
                'emptyMessage' => 'Der Feed ist leer (Filter/Score/Limit zu streng?).',
            ],
        ];

        $html = $this->renderer->render(
            $request->getStage(),
            $stages,
            self::FEED_TITLE,
            $request->getTimestamp(),
        );

        $indexFile = $outputDirectory . '/index.html';
        if (@file_put_contents($indexFile, $html) === false) {
            throw new RuntimeException('Konnte HTML-Datei nicht schreiben: ' . $indexFile);
        }

        $stageCounts = [
            FeedExportStage::Raw->value     => count($rawCards),
            FeedExportStage::Merged->value  => count($mergedCards),
            FeedExportStage::Curated->value => count($curatedCards),
        ];

        return new FeedExportResult(
            $outputDirectory,
            $imageDirectory,
            $indexFile,
            $copiedFileCount,
            $skippedThumbCount,
            count($curatedCards),
            $request->getStage(),
            $stageCounts,
        );
    }

    private function formatSummary(int $count, string $singular, string $plural): string
    {
        $word = $count === 1 ? $singular : $plural;

        return sprintf('%d %s', $count, $word);
    }

    /**
     * @param list<ClusterDraft> $drafts
     * @return list<array<string, mixed>>
     */
    private function buildDraftCards(
        array $drafts,
        FeedExportStage $stage,
        FeedExportRequest $request,
        string $imageDirectory,
        int &$copiedFileCount,
        int &$skippedThumbCount,
    ): array {
        $cards = [];

        foreach ($drafts as $index => $draft) {
            $card = $this->createDraftCard(
                $draft,
                $stage,
                $request,
                $imageDirectory,
                $copiedFileCount,
                $skippedThumbCount,
                (int) $index,
            );

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * @param list<MemoryFeedItem> $items
     * @return list<array<string, mixed>>
     */
    private function buildCuratedCards(
        array $items,
        FeedExportRequest $request,
        string $imageDirectory,
        int &$copiedFileCount,
        int &$skippedThumbCount,
    ): array {
        $cards = [];

        foreach ($items as $item) {
            $card = $this->createCuratedCard($item, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    private function createDraftCard(
        ClusterDraft $draft,
        FeedExportStage $stage,
        FeedExportRequest $request,
        string $imageDirectory,
        int &$copiedFileCount,
        int &$skippedThumbCount,
        int $position,
    ): ?array {
        $memberIds = $draft->getMembers();
        if ($memberIds === []) {
            return null;
        }

        $mediaItems = $this->fetchOrderedMedia($memberIds, false);
        $images     = $this->buildImageEntries($mediaItems, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);

        if ($images === []) {
            return null;
        }

        $params = $draft->getParams();
        $group  = $params['group'] ?? null;

        $chips   = [];
        $chips[] = $this->createChip($stage->label(), 'stage');
        $chips[] = $this->createChip('Algorithmus: ' . $draft->getAlgorithm());

        if (is_string($group) && $group !== '') {
            $chips[] = $this->createChip('Gruppe: ' . $group);
        }

        $chips[] = $this->createChip('Mitglieder: ' . $draft->getMembersCount());

        $storyline = $draft->getStoryline();
        if ($storyline !== '' && $storyline !== 'default') {
            $chips[] = $this->createChip('Storyline: ' . $this->prettifyStoryline($storyline));
        }

        $title = sprintf('Cluster %d', $position + 1);
        if ($storyline !== '' && $storyline !== 'default') {
            $title .= ' – ' . $this->prettifyStoryline($storyline);
        }

        $subtitle = $this->formatDraftSubtitle($draft);

        return [
            'title'    => $title,
            'subtitle' => $subtitle,
            'chips'    => $chips,
            'images'   => $images,
            'details'  => $this->buildDraftDetails($draft),
        ];
    }

    private function createCuratedCard(
        MemoryFeedItem $item,
        FeedExportRequest $request,
        string $imageDirectory,
        int &$copiedFileCount,
        int &$skippedThumbCount,
    ): ?array {
        $memberIds = $item->getMemberIds();
        if ($memberIds === []) {
            return null;
        }

        $mediaItems = $this->fetchOrderedMedia($memberIds, $item->getAlgorithm() === 'video_stories');
        $images     = $this->buildImageEntries($mediaItems, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);

        if ($images === []) {
            return null;
        }

        $params    = $item->getParams();
        $group     = $params['group'] ?? null;
        $sceneTags = $this->normalizeSceneTags($params['scene_tags'] ?? null);

        $chips   = [];
        $chips[] = $this->createChip('Feed-Ranking', 'stage');
        $chips[] = $this->createChip(sprintf('Score %s', number_format($item->getScore(), 3, ',', '')));
        $chips[] = $this->createChip('Algorithmus: ' . $item->getAlgorithm());
        $chips[] = $this->createChip('Mitglieder: ' . count($memberIds));

        if (is_string($group) && $group !== '') {
            $chips[] = $this->createChip('Gruppe: ' . $group);
        }

        if ($this->isCuratedForFeed($params, $memberIds)) {
            $chips[] = $this->createChip('Kuratiert', 'curated');
        }

        foreach (array_slice($sceneTags, 0, 3) as $tag) {
            $label = $tag['label'];
            $score = number_format($tag['score'], 2, ',', '');

            $chips[] = $this->createChip(sprintf('%s (%s)', $label, $score), 'tag');
        }

        return [
            'title'    => $item->getTitle(),
            'subtitle' => $item->getSubtitle(),
            'chips'    => $chips,
            'images'   => $images,
            'details'  => $this->buildCuratedDetails($params, $memberIds),
        ];
    }

    /**
     * @param list<int> $memberIds
     * @return list<Media>
     */
    private function fetchOrderedMedia(array $memberIds, bool $preferVideoStory): array
    {
        if ($memberIds === []) {
            return [];
        }

        $members = $this->mediaRepository->findByIds($memberIds, $preferVideoStory);

        $order = [];
        foreach ($memberIds as $index => $id) {
            $order[$id] = $index;
        }

        $members = array_values(array_filter(
            $members,
            static fn (Media $media): bool => array_key_exists($media->getId(), $order),
        ));

        usort($members, static function (Media $a, Media $b) use ($order): int {
            $posA = $order[$a->getId()] ?? PHP_INT_MAX;
            $posB = $order[$b->getId()] ?? PHP_INT_MAX;

            if ($posA === $posB) {
                return $a->getId() <=> $b->getId();
            }

            return $posA <=> $posB;
        });

        return $members;
    }

    /**
     * @param list<Media> $mediaItems
     * @return list<array{href:string, alt:string}>
     */
    private function buildImageEntries(
        array $mediaItems,
        FeedExportRequest $request,
        string $imageDirectory,
        int &$copiedFileCount,
        int &$skippedThumbCount,
    ): array {
        $images = [];

        foreach ($mediaItems as $media) {
            if (count($images) >= $request->getImagesPerItem()) {
                break;
            }

            $source = $this->thumbnailResolver->resolveBest($media, $request->getThumbnailWidth());
            if ($source === null) {
                ++$skippedThumbCount;

                continue;
            }

            $targetName = $this->thumbnailResolver->exportName($media, $source);
            $targetPath = $imageDirectory . '/' . $targetName;
            $href       = 'images/' . $targetName;

            if (!is_file($targetPath)) {
                $copiedFileCount += $this->copyOrLinkThumbnail($source, $targetPath, $request->useSymlinks());
            }

            $images[] = [
                'href' => $href,
                'alt'  => $this->buildAltText($media),
            ];
        }

        return $images;
    }

    /**
     * @return array{label:string, variant:string}
     */
    private function createChip(string $label, string $variant = 'default'): array
    {
        return [
            'label'   => $label,
            'variant' => $variant,
        ];
    }

    private function formatDraftSubtitle(ClusterDraft $draft): string
    {
        $range = $this->formatTimeRange($draft->getStartAt(), $draft->getEndAt());

        return sprintf(
            'Mitglieder: %d • Zeitraum: %s',
            $draft->getMembersCount(),
            $range ?? 'unbekannt',
        );
    }

    private function formatTimeRange(?DateTimeImmutable $start, ?DateTimeImmutable $end): ?string
    {
        if ($start === null && $end === null) {
            return null;
        }

        if ($start !== null && $end !== null) {
            $startFormatted = $start->format('d.m.Y H:i');
            $endFormatted   = $end->format('d.m.Y H:i');

            if ($startFormatted === $endFormatted) {
                return $startFormatted;
            }

            if ($start->format('d.m.Y') === $end->format('d.m.Y')) {
                return sprintf('%s – %s', $startFormatted, $end->format('H:i'));
            }

            return sprintf('%s – %s', $startFormatted, $endFormatted);
        }

        $single = $start ?? $end;

        return $single?->format('d.m.Y H:i');
    }

    private function formatCount(?int $count, string $singular, string $plural): ?string
    {
        if ($count === null) {
            return null;
        }

        $label = $count === 1 ? $singular : $plural;

        return sprintf('%d %s', $count, $label);
    }

    private function prettifyStoryline(string $storyline): string
    {
        $normalized = trim(str_replace(['_', '-'], ' ', $storyline));

        if ($normalized === '') {
            return 'Unbekannt';
        }

        return ucwords($normalized);
    }

    /**
     * @return list<string>
     */
    private function buildDraftDetails(ClusterDraft $draft): array
    {
        $details = [];

        $range = $this->formatTimeRange($draft->getStartAt(), $draft->getEndAt());
        if ($range !== null) {
            $details[] = 'Zeitraum: ' . $range;
        }

        $photoCount = $this->formatCount($draft->getPhotoCount(), 'Foto', 'Fotos');
        if ($photoCount !== null) {
            $details[] = 'Fotos: ' . $photoCount;
        }

        $videoCount = $this->formatCount($draft->getVideoCount(), 'Video', 'Videos');
        if ($videoCount !== null) {
            $details[] = 'Videos: ' . $videoCount;
        }

        $algorithmVersion = $draft->getAlgorithmVersion();
        if (is_string($algorithmVersion) && $algorithmVersion !== '') {
            $details[] = 'Algo-Version: ' . $algorithmVersion;
        }

        $configHash = $draft->getConfigHash();
        if (is_string($configHash) && $configHash !== '') {
            $details[] = 'Config: ' . $configHash;
        }

        return $details;
    }

    /**
     * @param array<string, scalar|array|null> $params
     * @param list<int>                        $memberIds
     *
     * @return list<string>
     */
    private function buildCuratedDetails(array $params, array $memberIds): array
    {
        $details   = [];
        $details[] = sprintf('Mitglieder: %d', count($memberIds));

        $storyline = $params['storyline'] ?? null;
        if (is_string($storyline) && $storyline !== '' && $storyline !== 'default') {
            $details[] = 'Storyline: ' . $this->prettifyStoryline($storyline);
        }

        return $details;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create output directory: ' . $directory);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param list<int>            $memberIds
     */
    private function isCuratedForFeed(array $params, array $memberIds): bool
    {
        $memberQuality = $params['member_quality'] ?? null;
        if (!is_array($memberQuality)) {
            return false;
        }

        $feedOverlay = $memberQuality['feed_overlay'] ?? null;
        if (is_array($feedOverlay)) {
            $used = $feedOverlay['used'] ?? null;
            if (is_bool($used)) {
                return $used;
            }
        }

        $ordered = $this->normaliseMemberIdList($memberQuality['ordered'] ?? null);
        if ($ordered === []) {
            return false;
        }

        $minimum = $this->resolveOverlayMinimum($memberQuality);
        if ($minimum > 0 && count($ordered) < $minimum) {
            return false;
        }

        $lookup   = [];
        $sequence = [];
        foreach ($ordered as $index => $id) {
            $lookup[$id] = $index;
        }

        foreach ($memberIds as $id) {
            if (array_key_exists($id, $lookup)) {
                $sequence[] = $id;
            }
        }

        if ($sequence !== $ordered) {
            return false;
        }

        if ($minimum > 0 && count($sequence) < $minimum) {
            return false;
        }

        return true;
    }

    /**
     * @param array<array-key, mixed>|null $values
     *
     * @return list<int>
     */
    private function normaliseMemberIdList(null|array $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        $seen   = [];

        foreach ($values as $value) {
            $id = null;
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $id = (int) $value;
            }

            if ($id === null || $id <= 0) {
                continue;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $result[]   = $id;
            $seen[$id] = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $memberQuality
     */
    private function resolveOverlayMinimum(array $memberQuality): int
    {
        $minimum = 0;

        $summary = $memberQuality['summary'] ?? null;
        if (is_array($summary)) {
            $profile = $summary['selection_profile'] ?? null;
            if (is_array($profile)) {
                $value = $profile['minimum_total'] ?? null;
                $min   = $this->normalisePositiveInt($value);
                if ($min !== null) {
                    $minimum = max($minimum, $min);
                }
            }

            $counts = $summary['selection_counts'] ?? null;
            if (is_array($counts)) {
                $value = $counts['curated'] ?? null;
                $min   = $this->normalisePositiveInt($value);
                if ($min !== null) {
                    $minimum = max($minimum, $min);
                }
            }
        }

        return $minimum;
    }

    private function normalisePositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            $candidate = (int) $value;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function copyOrLinkThumbnail(string $source, string $targetPath, bool $useSymlink): int
    {
        $linked = false;
        if ($useSymlink) {
            $linked = @symlink($source, $targetPath);
        }

        if ($linked) {
            return 1;
        }

        if (@copy($source, $targetPath)) {
            return 1;
        }

        return 0;
    }

    private function buildAltText(Media $media): string
    {
        $tags = $media->getSceneTags();
        if (is_array($tags)) {
            $parts = [];
            foreach (array_slice($tags, 0, 3) as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $label = $tag['label'] ?? null;
                $score = $tag['score'] ?? null;

                if (!is_string($label)) {
                    continue;
                }

                $text = $label;
                if (is_float($score) || is_int($score)) {
                    $formatted = number_format((float) $score, 2, ',', '');
                    $text      = sprintf('%s (%s)', $label, $formatted);
                }

                $parts[] = $text;
            }

            if ($parts !== []) {
                return 'Szene: ' . implode(', ', $parts);
            }
        }

        return basename($media->getPath());
    }

    /**
     * @param array|bool|float|int|string|null $value
     *
     * @return list<array{label: string, score: float}>
     */
    private function normalizeSceneTags(array|bool|float|int|string|null $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $label = $tag['label'] ?? null;
            $score = $tag['score'] ?? null;

            if (!is_string($label)) {
                continue;
            }

            if (!is_float($score) && !is_int($score)) {
                continue;
            }

            $valueScore = (float) $score;
            if ($valueScore < 0.0) {
                $valueScore = 0.0;
            }

            if ($valueScore > 1.0) {
                $valueScore = 1.0;
            }

            $result[] = ['label' => $label, 'score' => $valueScore];
        }

        return $result;
    }
}
