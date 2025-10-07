<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Support\IndexLogHelper;
use RuntimeException;
use Throwable;

use function is_file;
use function is_string;
use function mime_content_type;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * Orchestrates a sequence of specialized extractors.
 * Keeps IndexCommand unchanged: it still depends on MetadataExtractorInterface.
 */
final readonly class CompositeMetadataExtractor implements MetadataExtractorInterface
{
    /**
     * @var list<SingleMetadataExtractorInterface> prioritised extractor list executed in order of
     *                                             likelihood/cost to build up the composite metadata set
     */
    private array $extractors;

    /**
     * @param SingleMetadataExtractorInterface[] $extractors ordered list; cheap/likely first
     */
    public function __construct(array $extractors)
    {
        $this->extractors = $extractors;
    }

    /**
     * Runs all supporting extractors sequentially to enrich the given media metadata.
     * Unsupported extractors are skipped, while supported ones merge their output into the same
     * Media instance so that later extractors can extend earlier results. The method mutates the
     * supplied entity by guessing a MIME type when none is present before any extractor executes.
     *
     * @param string $filepath absolute path to the media file currently processed
     * @param Media  $media    media entity to populate; receives a MIME type guess when missing
     *
     * @return Media media entity that contains the aggregated metadata from all supporting extractors
     */
    public function extract(string $filepath, Media $media): Media
    {
        $this->ensureMimeType($filepath, $media);

        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($filepath, $media) === true) {
                $media = $extractor->extract($filepath, $media);
            }
        }

        return $media;
    }

    private function ensureMimeType(string $filepath, Media $media): void
    {
        if ($media->getMime() !== null) {
            return;
        }

        if (is_file($filepath) === false) {
            IndexLogHelper::append($media, 'MIME-Bestimmung übersprungen: Datei nicht gefunden.');

            return;
        }

        $handler = static function (int $severity, string $message): bool {
            $text = $message !== '' ? $message : 'Unbekannter Fehler bei mime_content_type.';

            throw new RuntimeException($text, $severity);
        };

        set_error_handler($handler);

        try {
            $mime = mime_content_type($filepath);
        } catch (Throwable $exception) {
            IndexLogHelper::append(
                $media,
                sprintf('MIME-Bestimmung fehlgeschlagen: %s', $exception->getMessage())
            );

            return;
        } finally {
            restore_error_handler();
        }

        if (is_string($mime) && $mime !== '') {
            $media->setMime($mime);
            IndexLogHelper::append($media, sprintf('MIME-Bestimmung erfolgreich: %s', $mime));

            return;
        }

        IndexLogHelper::append($media, 'MIME-Bestimmung fehlgeschlagen: Keine gültige Antwort erhalten.');
    }
}
