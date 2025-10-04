<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Exif\Processor;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Exif\Contract\ExifMetadataProcessorInterface;
use MagicSunday\Memories\Service\Metadata\Exif\Contract\ExifValueAccessorInterface;
use MagicSunday\Memories\Service\Metadata\Support\MediaFormatGuesser;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Derives format flags (RAW/HEIC/HEVC) from EXIF file metadata.
 */
#[AutoconfigureTag('memories.metadata.exif.processor', ['priority' => 80])]
final class FormatFlagExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private readonly ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        $fileType = $this->accessor->strOrNull($exif['FILE']['FileType'] ?? null);
        if ($fileType === null) {
            return;
        }

        if (MediaFormatGuesser::isRawFromFileType($fileType)) {
            $media->setIsRaw(true);
        }

        if (MediaFormatGuesser::isHeicFromFileType($fileType)) {
            $media->setIsHeic(true);
        }

        if (MediaFormatGuesser::isHevcFromFileType($fileType)) {
            $media->setIsHevc(true);
        }
    }
}
