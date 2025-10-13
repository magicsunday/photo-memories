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
 * Derives portrait and panorama flags from the media's aspect ratio.
 */
#[AutoconfigureTag('memories.metadata.exif.processor', ['priority' => 0])]
final class AspectFlagExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function process(array $exif, Media $media): void
    {
        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return;
        }

        $isPortrait = $height > $width && ((float) $height / (float) $width) >= 1.2;
        $isPanorama = $width > $height && ((float) $width / (float) $height) >= 2.4;

        $media->setIsPortrait($isPortrait);
        $media->setIsPanorama($isPanorama);
    }
}
