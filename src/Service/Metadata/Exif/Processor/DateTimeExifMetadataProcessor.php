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
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Applies capture date, timezone offset and sub-second precision from EXIF data.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final class DateTimeExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private readonly ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        $takenAt = $this->accessor->findDate($exif);
        if ($takenAt !== null) {
            $media->setTakenAt($takenAt);
        }

        $offset = $this->accessor->parseOffsetMinutes($exif);
        if ($offset !== null) {
            $media->setTimezoneOffsetMin($offset);
        }

        $subSeconds = $this->accessor->intOrNull($exif['EXIF']['SubSecTimeOriginal'] ?? null);
        if ($subSeconds !== null) {
            $media->setSubSecOriginal($subSeconds);
        }
    }
}
