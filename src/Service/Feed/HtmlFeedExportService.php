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

use function array_slice;
use function basename;
use function copy;
use function count;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function is_string;
use function symlink;
use function usort;

final class HtmlFeedExportService implements FeedExportServiceInterface
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

        $cards             = [];
        $copiedFileCount   = 0;
        $skippedThumbCount = 0;

        foreach ($items as $item) {
            $cardData = $this->createCard($item, $request, $imageDirectory, $copiedFileCount, $skippedThumbCount);
            if ($cardData === null) {
                continue;
            }

            $cards[] = $cardData;
        }

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

        $members = $this->mediaRepository->findByIds($memberIds);

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
                'alt'  => basename($media->getPath()),
            ];
        }

        if ($images === []) {
            return null;
        }

        $params = $item->getParams();
        $group  = $params['group'] ?? null;

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
}
