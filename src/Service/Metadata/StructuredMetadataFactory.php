<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;

use function abs;
use function array_filter;
use function implode;
use function number_format;
use function round;
use function sprintf;
use function strtolower;

/**
 * Builds structured metadata sections from an enriched media entity.
 *
 * @psalm-type StructuredPayload = array<string, array<string, bool|int|float|string|null|array|object>>
 */
final readonly class StructuredMetadataFactory
{
    public function create(Media $media): StructuredMetadata
    {
        return StructuredMetadata::fromArray([
            'lens' => $this->buildLensSection($media),
            'camera' => $this->buildCameraSection($media),
            'image' => $this->buildImageSection($media),
            'exposure' => $this->buildExposureSection($media),
            'gps' => $this->buildGpsSection($media),
            'preview' => $this->buildPreviewSection($media),
            'interop' => [],
            'standards' => [],
            'derived' => $this->buildDerivedSection($media),
        ]);
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    private function buildLensSection(Media $media): array
    {
        return array_filter([
            'make' => $media->getLensMake(),
            'model' => $media->getLensModel(),
            'specification' => $media->getLensSpecification(),
            'serial' => $media->getLensSerialNumber(),
            'summary' => $this->formatSummary(
                $media->getLensMake(),
                $media->getLensModel(),
                $media->getLensSpecification()
            ),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    private function buildCameraSection(Media $media): array
    {
        return array_filter([
            'make' => $media->getCameraMake(),
            'model' => $media->getCameraModel(),
            'owner' => $media->getCameraOwner(),
            'serial' => $media->getCameraBodySerial(),
            'summary' => $this->formatSummary($media->getCameraMake(), $media->getCameraModel()),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    private function buildImageSection(Media $media): array
    {
        return array_filter([
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            'orientation' => $media->getOrientation(),
            'orientation_label' => $this->formatOrientation($media->getOrientation()),
            'dimensions' => $this->formatDimensions($media->getWidth(), $media->getHeight()),
            'mime' => $media->getMime(),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    private function buildExposureSection(Media $media): array
    {
        return array_filter([
            'focal_length_mm' => $media->getFocalLengthMm(),
            'focal_length_text' => $this->formatFocalLength($media->getFocalLengthMm()),
            'focal_length_35mm' => $media->getFocalLength35mm(),
            'aperture_f' => $media->getApertureF(),
            'aperture_text' => $this->formatAperture($media->getApertureF()),
            'exposure_time_s' => $media->getExposureTimeS(),
            'exposure_text' => $this->formatExposureTime($media->getExposureTimeS()),
            'iso' => $media->getIso(),
            'flash_fired' => $media->isFlashFired(),
            'flash_text' => $this->formatFlash($media->isFlashFired()),
            'composite_image' => $media->getCompositeImage(),
            'composite_source_count' => $media->getCompositeImageSourceCount(),
            'composite_exposure_times' => $media->getCompositeImageExposureTimes(),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    private function buildGpsSection(Media $media): array
    {
        return array_filter([
            'lat' => $media->getGpsLat(),
            'lon' => $media->getGpsLon(),
            'alt' => $media->getGpsAlt(),
            'accuracy_m' => $media->getGpsAccuracyM(),
            'heading_deg' => $media->getGpsHeadingDeg(),
            'speed_mps' => $media->getGpsSpeedMps(),
            'coordinates' => $this->formatCoordinates($media->getGpsLat(), $media->getGpsLon()),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    private function buildPreviewSection(Media $media): array
    {
        return array_filter([
            'phash' => strtolower($media->getPhash() ?? ''),
            'phash64' => $media->getPhash64(),
            'phash_prefix' => strtolower($media->getPhashPrefix() ?? ''),
            'dhash' => strtolower($media->getDhash() ?? ''),
            'ahash' => strtolower($media->getAhash() ?? ''),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, bool|int|float|string|null>
     */
    private function buildDerivedSection(Media $media): array
    {
        return array_filter([
            'taken_at' => $media->getTakenAt()?->format('c'),
            'captured_local' => $media->getCapturedLocal()?->format('c'),
            'timezone' => $media->getTzId(),
            'timezone_offset_min' => $media->getTimezoneOffsetMin(),
            'time_source' => $media->getTimeSource()?->value,
            'distance_km_from_home' => $media->getDistanceKmFromHome(),
            'home_config_hash' => $media->getHomeConfigHash(),
            'content_kind' => $media->getContentKind()?->value,
            'video_duration_s' => $media->getVideoDurationS(),
            'video_fps' => $media->getVideoFps(),
            'slow_mo' => $media->isSlowMo(),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function formatSummary(?string ...$parts): ?string
    {
        $filtered = array_filter($parts, static fn (?string $value): bool => $value !== null && $value !== '');

        if ($filtered === []) {
            return null;
        }

        return implode(' ', $filtered);
    }

    private function formatDimensions(?int $width, ?int $height): ?string
    {
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        return sprintf('%d × %d', $width, $height);
    }

    private function formatOrientation(?int $orientation): ?string
    {
        return match ($orientation) {
            1 => 'Normal',
            2 => 'Horizontal gespiegelt',
            3 => '180° gedreht',
            4 => 'Vertikal gespiegelt',
            5 => '90° gedreht & gespiegelt',
            6 => '90° gedreht',
            7 => '270° gedreht & gespiegelt',
            8 => '270° gedreht',
            default => null,
        };
    }

    private function formatFocalLength(?float $value): ?string
    {
        if ($value === null || $value <= 0.0) {
            return null;
        }

        return sprintf('%s mm', $this->trimNumber($value));
    }

    private function formatAperture(?float $value): ?string
    {
        if ($value === null || $value <= 0.0) {
            return null;
        }

        return sprintf('f/%s', $this->trimNumber($value));
    }

    private function formatExposureTime(?float $value): ?string
    {
        if ($value === null || $value <= 0.0) {
            return null;
        }

        if ($value >= 1.0) {
            return sprintf('%s s', $this->trimNumber($value));
        }

        $denominator = (int) round(1.0 / $value);
        if ($denominator <= 0) {
            return sprintf('%s s', $this->trimNumber($value));
        }

        return sprintf('1/%d s', $denominator);
    }

    private function formatFlash(?bool $flashFired): ?string
    {
        if ($flashFired === null) {
            return null;
        }

        return $flashFired ? 'Blitz ausgelöst' : 'Blitz nicht ausgelöst';
    }

    private function formatCoordinates(?float $lat, ?float $lon): ?string
    {
        if ($lat === null || $lon === null) {
            return null;
        }

        return sprintf('%s, %s', $this->formatCoordinate($lat), $this->formatCoordinate($lon));
    }

    private function formatCoordinate(float $value): string
    {
        $rounded = round($value, 6);
        $sign = $rounded >= 0.0 ? '' : '-';

        return $sign . number_format(abs($rounded), 6, '.', '');
    }

    private function trimNumber(float $value): string
    {
        $rounded = round($value, 2);
        $formatted = number_format($rounded, 2, '.', '');
        $formatted = rtrim($formatted, '0');

        if ($formatted === '' || $formatted === '.') {
            return '0';
        }

        return rtrim($formatted, '.');
    }
}
