<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

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
use function symlink;
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

            return new FeedExportResult($outputDirectory, $imageDirectory, null, 0, 0, 0);
        }

        $drafts       = $this->mapper->mapMany($entities);
        $consolidated = $this->consolidator->consolidate($drafts);
        if ($consolidated === []) {
            $io->warning('Keine Cluster nach der Konsolidierung.');

            return new FeedExportResult($outputDirectory, $imageDirectory, null, 0, 0, 0);
        }

        $items = $this->feedBuilder->build($consolidated);
        if ($items === []) {
            $io->warning('Der Feed ist leer (Filter/Score/Limit zu streng?).');

            return new FeedExportResult($outputDirectory, $imageDirectory, null, 0, 0, 0);
        }

        if (count($items) > $request->getMaxItems()) {
            $items = array_slice($items, 0, $request->getMaxItems());
        }

        $copiedFileCount   = 0;
        $skippedThumbCount = 0;

        /** @var list<array<string, mixed>|null> $cardCandidates */
        $cardCandidates = [];
        foreach ($items as $item) {
            $cardCandidates[] = $this->createCard($item, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);
        }

        /** @var list<array<string, mixed>> $cards */
        $cards = array_values(array_filter(
            $cardCandidates,
            static fn (?array $cardData): bool => $cardData !== null,
        ));

        if ($cards === []) {
            $io->warning('Keine Bilder für die HTML-Ausgabe gefunden.');

            return new FeedExportResult($outputDirectory, $imageDirectory, null, $copiedFileCount, $skippedThumbCount, 0);
        }

        $html = $this->renderer->render($cards, self::FEED_TITLE);

        $indexFile = $outputDirectory . '/index.html';
        if (@file_put_contents($indexFile, $html) === false) {
            throw new RuntimeException('Konnte HTML-Datei nicht schreiben: ' . $indexFile);
        }

        return new FeedExportResult($outputDirectory, $imageDirectory, $indexFile, $copiedFileCount, $skippedThumbCount, count($cards));
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
     * @return array<string, mixed>|null
     */
    private function createCard(
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

        $members = $this->mediaRepository->findByIds(
            $memberIds,
            $item->getAlgorithm() === 'video_stories'
        );

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

        $images = [];
        foreach ($members as $media) {
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

        if ($images === []) {
            return null;
        }

        $params = $item->getParams();
        $group  = $params['group'] ?? null;

        $sceneTags = $this->normalizeSceneTags($params['scene_tags'] ?? null);

        $curated = $this->isCuratedForFeed($params, $memberIds);

        $card = [
            'title'     => $item->getTitle(),
            'subtitle'  => $item->getSubtitle(),
            'algorithm' => $item->getAlgorithm(),
            'score'     => $item->getScore(),
            'images'    => $images,
        ];

        if ($curated) {
            $card['curated'] = true;
        }

        if (is_string($group) && $group !== '') {
            $card['group'] = $group;
        }

        if ($sceneTags !== []) {
            $card['sceneTags'] = $sceneTags;
        }

        return $card;
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
