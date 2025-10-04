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

use function array_key_exists;
use function exif_read_data;
use function is_array;
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
    /** Map PHP's "UndefinedTag" keys to their EXIF 2.31 names. */
    private const array OFFSET_ALIASES = [
        'UndefinedTag:0x001F' => 'GPSHPositioningError',
        'UndefinedTag:0xA430' => 'CameraOwnerName',
        'UndefinedTag:0xA431' => 'BodySerialNumber',
        'UndefinedTag:0xA432' => 'LensSpecification',
        'UndefinedTag:0xA433' => 'LensMake',
        'UndefinedTag:0xA434' => 'LensModel',
        'UndefinedTag:0xA435' => 'LensSerialNumber',
        'UndefinedTag:0x9010' => 'OffsetTime',
        'UndefinedTag:0x9011' => 'OffsetTimeOriginal',
        'UndefinedTag:0x9012' => 'OffsetTimeDigitized',
        'UndefinedTag:0x882A' => 'TimeZoneOffset',
        'UndefinedTag:0xA460' => 'Gamma',
        'UndefinedTag:0xA461' => 'CompositeImage',
        'UndefinedTag:0xA462' => 'SourceImageNumberOfCompositeImage',
        'UndefinedTag:0xA463' => 'SourceExposureTimesOfCompositeImage',
    ];

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

        $exif = $this->normalizeUndefinedTagOffsets($exif);

        foreach ($this->processors as $processor) {
            $processor->process($exif, $media);
        }

        return $media;
    }

    /**
     * Recursively traverses the full EXIF array and, at every nesting level,
     * adds friendly keys (OffsetTime*, TimeZoneOffset, Lens*, etc.) next to any "UndefinedTag:*" entry.
     *
     * @param array<string, mixed> $exif
     * @param bool                 $preserveOriginal
     *
     * @return array<string, mixed>
     */
    private function normalizeUndefinedTagOffsets(array $exif, bool $preserveOriginal = true): array
    {
        return $this->normalizeUndefinedTagOffsetsNode($exif, $preserveOriginal);
    }

    /**
     * @param array<string, mixed> $node
     * @param bool                 $preserveOriginal
     *
     * @return array<string, mixed>
     */
    private function normalizeUndefinedTagOffsetsNode(array $node, bool $preserveOriginal): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $node[$key] = $this->normalizeUndefinedTagOffsetsNode($value, $preserveOriginal);
            }
        }

        foreach (self::OFFSET_ALIASES as $ugly => $nice) {
            if (array_key_exists($ugly, $node) && !array_key_exists($nice, $node)) {
                $node[$nice] = $node[$ugly];
            }
        }

        if ($preserveOriginal === false) {
            foreach (self::OFFSET_ALIASES as $ugly => $nice) {
                if (array_key_exists($ugly, $node)) {
                    unset($node[$ugly]);
                }
            }
        }

        return $node;
    }
}
