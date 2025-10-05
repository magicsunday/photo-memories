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
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Sets missing width/height information from the EXIF COMPUTED section.
 */
#[AutoconfigureTag('memories.metadata.exif.processor', ['priority' => 100])]
final class DimensionsExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function process(array $exif, Media $media): void
    {
        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width !== null && $height !== null && $width > 0 && $height > 0) {
            return;
        }

        $computedWidth  = isset($exif['COMPUTED']['Width']) ? (int) $exif['COMPUTED']['Width'] : null;
        $computedHeight = isset($exif['COMPUTED']['Height']) ? (int) $exif['COMPUTED']['Height'] : null;

        if ($width === null && $computedWidth !== null && $computedWidth > 0) {
            $media->setWidth($computedWidth);
        }

        if ($height === null && $computedHeight !== null && $computedHeight > 0) {
            $media->setHeight($computedHeight);
        }
    }
}
