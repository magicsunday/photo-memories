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
use MagicSunday\Memories\Service\Metadata\Exif\Contract\ExifMetadataProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Throwable;

use function exif_read_data;
use function is_file;
use function str_starts_with;

/**
 * Extracts EXIF metadata from images (and optionally videos) and enriches Media.
 *
 * Notes:
 * - Idempotent: only sets fields it owns and only when values are available.
 * - Robust date parsing for "YYYY:MM:DD HH:MM:SS" and OffsetTime(Original).
 * - GPS: converts DMS to decimal; GPSSpeedRef K/M/N -> m/s; altitude sign via GPSAltitudeRef.
 * - Fallback for dimensions via EXIF COMPUTED if Media width/height are not set.
 */
final readonly class ExifMetadataExtractor implements SingleMetadataExtractorInterface
{
    /**
     * @param iterable<ExifMetadataProcessorInterface> $processors
     */
    public function __construct(
        #[TaggedIterator('memories.metadata.exif.processor')]
        private iterable $processors,
        private bool $readExifForVideos = false,
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();
        if ($mime === null) {
            return false;
        }

        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        return $this->readExifForVideos && str_starts_with($mime, 'video/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        if (!is_file($filepath)) {
            // Not considered a fatal extraction error; simply no-op.
            return $media;
        }

        try {
            /** @var array<string,mixed>|false $exif */
            $exif = @exif_read_data($filepath, null, true, false);
        } catch (Throwable) {
            $exif = false;
        }

        if ($exif === false) {
            return $media;
        }

        foreach ($this->processors as $processor) {
            $processor->process($exif, $media);
        }

        return $media;
    }
}
