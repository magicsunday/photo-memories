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
use MagicSunday\Memories\Service\Metadata\Exif\Value\GpsMetadata;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use function is_array;

/**
 * Enriches the media entity with GPS coordinates, altitude, speed and course.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final readonly class GpsExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        if (!isset($exif['GPS']) || !is_array($exif['GPS'])) {
            return;
        }

        $metadata = $this->accessor->gpsFromExif($exif['GPS']);
        if (!$metadata instanceof GpsMetadata) {
            return;
        }

        $media->setGpsLat($metadata->latitude);
        $media->setGpsLon($metadata->longitude);

        if ($metadata->altitude !== null) {
            $media->setGpsAlt($metadata->altitude);
        }

        if ($metadata->horizontalAccuracyMeters !== null) {
            $media->setGpsAccuracyM($metadata->horizontalAccuracyMeters);
        }

        if ($metadata->speedMetersPerSecond !== null) {
            $media->setGpsSpeedMps($metadata->speedMetersPerSecond);
        }

        if ($metadata->courseDegrees !== null) {
            $media->setGpsHeadingDeg($metadata->courseDegrees);
        }
    }
}
