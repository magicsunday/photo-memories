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
use function array_map;
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
use function is_float;
use function is_int;
use function is_string;
use function mkdir;
use function number_format;
use function sprintf;
use function symlink;
use function usort;

/**
 * Class HtmlFeedExportService
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
        $cardCandidates = array_map(
            fn (MemoryFeedItem $item): ?array => $this->createCard($item, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount),
            $items,
        );

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

        $coverId = $item->getCoverMediaId();
        usort($members, static function (Media $a, Media $b) use ($coverId): int {
            if ($coverId !== null) {
                if ($a->getId() === $coverId && $b->getId() !== $coverId) {
                    return -1;
                }

                if ($b->getId() === $coverId && $a->getId() !== $coverId) {
                    return 1;
                }
            }

            $timestampA = $a->getTakenAt()?->getTimestamp() ?? 0;
            $timestampB = $b->getTakenAt()?->getTimestamp() ?? 0;

            return $timestampA <=> $timestampB;
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

        $card = [
            'title'     => $item->getTitle(),
            'subtitle'  => $item->getSubtitle(),
            'algorithm' => $item->getAlgorithm(),
            'score'     => $item->getScore(),
            'images'    => $images,
        ];

        if (is_string($group) && $group !== '') {
            $card['group'] = $group;
        }

        if ($sceneTags !== []) {
            $card['sceneTags'] = $sceneTags;
        }

        return $card;
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
