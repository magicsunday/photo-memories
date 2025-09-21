<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;

/**
 * Orchestrates a sequence of specialized extractors.
 * Keeps IndexCommand unchanged: it still depends on MetadataExtractorInterface.
 */
final class CompositeMetadataExtractor implements MetadataExtractorInterface
{
    /** @var SingleMetadataExtractorInterface[] */
    private array $extractors;

    /**
     * @param SingleMetadataExtractorInterface[] $extractors Ordered list; cheap/likely first.
     */
    public function __construct(array $extractors)
    {
        $this->extractors = $extractors;
    }

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
