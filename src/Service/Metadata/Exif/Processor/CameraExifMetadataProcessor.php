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

use function abs;
use function implode;
use function is_array;
use function round;
use function rtrim;
use function sprintf;

/**
 * Extracts camera and lens information from EXIF data.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final readonly class CameraExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private ExifValueAccessorInterface $accessor,
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

        $owner = $this->accessor->strOrNull($exif['EXIF']['CameraOwnerName'] ?? null);
        if ($owner !== null) {
            $media->setCameraOwner($owner);
        }

        $bodySerial = $this->accessor->strOrNull($exif['EXIF']['BodySerialNumber'] ?? null);
        if ($bodySerial !== null) {
            $media->setCameraBodySerial($bodySerial);
        }

        $lensMake = $this->accessor->strOrNull($exif['EXIF']['LensMake'] ?? null);
        if ($lensMake !== null) {
            $media->setLensMake($lensMake);
        }

        $lens = $this->accessor->strOrNull($exif['EXIF']['LensModel'] ?? null);
        if ($lens !== null) {
            $media->setLensModel($lens);
        }

        $lensSpecification = $this->normaliseLensSpecification($exif['EXIF']['LensSpecification'] ?? null);
        if ($lensSpecification !== null) {
            $media->setLensSpecification($lensSpecification);
        }

        $lensSerial = $this->accessor->strOrNull($exif['EXIF']['LensSerialNumber'] ?? null);
        if ($lensSerial !== null) {
            $media->setLensSerialNumber($lensSerial);
        }
    }

    private function normaliseLensSpecification(mixed $value): ?string
    {
        if (!is_array($value)) {
            return $this->accessor->strOrNull($value);
        }

        $focalMin    = $this->accessor->floatOrRational($value[0] ?? null);
        $focalMax    = $this->accessor->floatOrRational($value[1] ?? null);
        $apertureMin = $this->accessor->floatOrRational($value[2] ?? null);
        $apertureMax = $this->accessor->floatOrRational($value[3] ?? null);

        $parts = [];

        $focal = $this->formatFocalRange($focalMin, $focalMax);
        if ($focal !== null) {
            $parts[] = $focal;
        }

        $aperture = $this->formatApertureRange($apertureMin, $apertureMax);
        if ($aperture !== null) {
            $parts[] = $aperture;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function formatFocalRange(?float $min, ?float $max): ?string
    {
        $min = $this->positiveOrNull($min);
        $max = $this->positiveOrNull($max);

        if ($min === null && $max === null) {
            return null;
        }

        if ($min === null) {
            $min = $max;
        }

        if ($max === null) {
            $max = $min;
        }

        if ($min === null || $max === null) {
            return null;
        }

        if (abs($max - $min) < 0.01) {
            return sprintf('%smm', $this->formatNumber($min));
        }

        return sprintf('%s-%smm', $this->formatNumber($min), $this->formatNumber($max));
    }

    private function formatApertureRange(?float $min, ?float $max): ?string
    {
        $min = $this->positiveOrNull($min);
        $max = $this->positiveOrNull($max);

        if ($min === null && $max === null) {
            return null;
        }

        if ($min === null) {
            $min = $max;
        }

        if ($max === null) {
            $max = $min;
        }

        if ($min === null || $max === null) {
            return null;
        }

        if (abs($max - $min) < 0.01) {
            return sprintf('f/%s', $this->formatNumber($min));
        }

        return sprintf('f/%s-%s', $this->formatNumber($min), $this->formatNumber($max));
    }

    private function positiveOrNull(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return $value > 0.0 ? $value : null;
    }

    private function formatNumber(float $value): string
    {
        $rounded = round($value, 2);
        $string  = sprintf('%.2f', $rounded);

        return rtrim(rtrim($string, '0'), '.');
    }
}
