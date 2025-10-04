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
 * Extracts camera and lens information from EXIF data.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final class CameraExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private readonly ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        $make = $this->accessor->strOrNull($exif['IFD0']['Make'] ?? null);
        if ($make !== null) {
            $media->setCameraMake($make);
        }

        $model = $this->accessor->strOrNull($exif['IFD0']['Model'] ?? null);
        if ($model !== null) {
            $media->setCameraModel($model);
        }

        $lens = $this->accessor->strOrNull($exif['EXIF']['LensModel'] ?? null);
        if ($lens !== null) {
            $media->setLensModel($lens);
        }
    }
}
