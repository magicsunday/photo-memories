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

/**
 * Orchestrates a sequence of specialized extractors.
 * Keeps IndexCommand unchanged: it still depends on MetadataExtractorInterface.
 */
final readonly class CompositeMetadataExtractor implements MetadataExtractorInterface
{
    /**
     * @var list<SingleMetadataExtractorInterface> $extractors Prioritised extractor list executed in order of
     *                                                         likelihood/cost to build up the composite metadata set.
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
     * @param string $filepath Absolute path to the media file currently processed.
     * @param Media  $media    Media entity to populate; receives a MIME type guess when missing.
     *
     * @return Media Media entity that contains the aggregated metadata from all supporting extractors.
     */
    public function extract(string $filepath, Media $media): Media
    {
        // Ensure mime is present early (if not set yet, guess it)
        if ($media->getMime() === null) {
            $mime = mime_content_type($filepath) ?: null;
            $media->setMime($mime);
        }

        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($filepath, $media) === true) {
                $media = $extractor->extract($filepath, $media);
            }
        }

        return $media;
    }
}
