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
 * Maps orientation data onto the media entity.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final readonly class OrientationExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        $orientation = $this->accessor->intOrNull($exif['IFD0']['Orientation'] ?? null);
        if ($orientation !== null) {
            $media->setOrientation($orientation);
            $media->setNeedsRotation($orientation > 1);
        }
    }
}
