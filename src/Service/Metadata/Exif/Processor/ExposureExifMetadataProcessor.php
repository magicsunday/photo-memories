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
 * Maps exposure related metadata such as focal length and ISO.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final readonly class ExposureExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        $focalLength = $this->accessor->floatOrRational($exif['EXIF']['FocalLength'] ?? null);
        if ($focalLength !== null) {
            $media->setFocalLengthMm($focalLength);
        }

        $focalLength35 = $this->accessor->intOrNull($exif['EXIF']['FocalLengthIn35mmFilm'] ?? null);
        if ($focalLength35 !== null) {
            $media->setFocalLength35mm($focalLength35);
        }

        $aperture = $this->accessor->floatOrRational($exif['EXIF']['FNumber'] ?? null);
        if ($aperture !== null) {
            $media->setApertureF($aperture);
        }

        $exposureTime = $this->accessor->exposureToSeconds($exif['EXIF']['ExposureTime'] ?? null);
        if ($exposureTime !== null) {
            $media->setExposureTimeS($exposureTime);
        }

        $iso = $this->accessor->intFromScalarOrArray(
            $exif['EXIF']['ISOSpeedRatings'] ?? ($exif['EXIF']['PhotographicSensitivity'] ?? null)
        );
        if ($iso !== null) {
            $media->setIso($iso);
        }

        $flash = $this->accessor->intOrNull($exif['EXIF']['Flash'] ?? null);
        if ($flash !== null) {
            $media->setFlashFired(($flash & 1) === 1);
        }
    }
}
