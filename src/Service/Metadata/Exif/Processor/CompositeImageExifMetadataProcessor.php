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
use function is_array;

/**
 * Persists composite image EXIF metadata onto the media entity.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final class CompositeImageExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private readonly ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        if (!isset($exif['EXIF']) || !is_array($exif['EXIF'])) {
            return;
        }

        $compositeImage = $this->accessor->intOrNull($exif['EXIF']['CompositeImage'] ?? null);
        if ($compositeImage !== null) {
            $media->setCompositeImage($compositeImage);
        }

        $sourceCount = $this->accessor->intOrNull($exif['EXIF']['SourceImageNumberOfCompositeImage'] ?? null);
        if ($sourceCount !== null) {
            $media->setCompositeImageSourceCount($sourceCount);
        }

        $exposureTimes = $this->accessor->strOrNull($exif['EXIF']['SourceExposureTimesOfCompositeImage'] ?? null);
        if ($exposureTimes !== null) {
            $media->setCompositeImageExposureTimes($exposureTimes);
        }
    }
}
