<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Stage;

use DateTimeInterface;
use JsonException;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use MagicSunday\Memories\Service\Metadata\MetadataQaInspectionResult;
use RuntimeException;

use function dirname;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;
use const PHP_EOL;

/**
 * Exports consolidated media signals to a typed JSON payload for downstream indices.
 */
final class MetaExportStage implements MediaIngestionStageInterface
{
    private const string OUTPUT_FILENAME = 'media_index.meta';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION;

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped() || $context->isDryRun()) {
            return $context;
        }

        $media = $context->getMedia();
        if (!$media instanceof Media) {
            return $context;
        }

        $targetDir = dirname($media->getPath());
        if ($targetDir === '' || is_dir($targetDir) === false) {
            throw new RuntimeException(sprintf('Target directory "%s" is not accessible for media export.', $targetDir));
        }

        $payload = $this->buildPayload($media, $context->getQaFindings());

        try {
            $json = json_encode($payload, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode media meta export payload.', 0, $exception);
        }

        $metaPath = $targetDir . DIRECTORY_SEPARATOR . self::OUTPUT_FILENAME;
        $this->dumpFile($metaPath, $json . PHP_EOL);

        return $context;
    }

    /**
     * @param list<MetadataQaInspectionResult> $qaFindings
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Media $media, array $qaFindings): array
    {
        return [
            'schema_version' => 1,
            'identity' => [
                'path' => $media->getPath(),
                'created_at' => $this->formatDate($media->getCreatedAt()),
                'indexed_at' => $this->formatDate($media->getIndexedAt()),
                'feature_version' => $media->getFeatureVersion(),
                'checksum_sha256' => $media->getChecksum(),
                'fast_checksum_xxhash64' => $media->getFastChecksumXxhash64(),
                'size_bytes' => $media->getSize(),
                'mime' => $media->getMime(),
            ],
            'flags' => [
                'is_video' => $media->isVideo(),
                'is_raw' => $media->isRaw(),
                'is_heic' => $media->isHeic(),
                'is_hevc' => $media->isHevc(),
                'needs_rotation' => $media->needsRotation(),
                'needs_geocode' => $media->needsGeocode(),
                'no_show' => $media->isNoShow(),
            ],
            'capture' => [
                'taken_at' => $this->formatDate($media->getTakenAt()),
                'captured_local' => $this->formatDate($media->getCapturedLocal()),
                'time_source' => $media->getTimeSource()?->value,
                'timezone' => [
                    'id' => $media->getTzId(),
                    'confidence' => $media->getTzConfidence(),
                    'offset_minutes' => $media->getTimezoneOffsetMin(),
                ],
                'subsecond' => $media->getSubSecOriginal(),
            ],
            'dimensions' => [
                'width' => $media->getWidth(),
                'height' => $media->getHeight(),
                'orientation' => $media->getOrientation(),
            ],
            'spatial' => [
                'gps' => [
                    'lat' => $media->getGpsLat(),
                    'lon' => $media->getGpsLon(),
                    'alt' => $media->getGpsAlt(),
                    'accuracy_m' => $media->getGpsAccuracyM(),
                    'heading_deg' => $media->getGpsHeadingDeg(),
                    'speed_mps' => $media->getGpsSpeedMps(),
                ],
                'hashes' => [
                    'geo_cell8' => $media->getGeoCell8(),
                    's2_cell_id' => $media->getS2CellId(),
                    'geohash7' => $media->getGeohash7(),
                    'geohash5' => $media->getGeohash5(),
                ],
                'distance_km_from_home' => $media->getDistanceKmFromHome(),
                'home_config_hash' => $media->getHomeConfigHash(),
                'location' => $this->serialiseLocation($media->getLocation()),
                'place' => $media->getPlaceIdPayload(),
            ],
            'video' => [
                'video_duration_s' => $media->getVideoDurationS(),
                'video_fps' => $media->getVideoFps(),
                'video_codec' => $media->getVideoCodec(),
                'video_streams' => $media->getVideoStreams(),
                'video_rotation_deg' => $media->getVideoRotationDeg(),
                'video_has_stabilization' => $media->getVideoHasStabilization(),
                'is_slow_mo' => $media->isSlowMo(),
            ],
            'imaging' => [
                'camera' => [
                    'make' => $media->getCameraMake(),
                    'model' => $media->getCameraModel(),
                    'owner' => $media->getCameraOwner(),
                    'serial' => $media->getCameraBodySerial(),
                ],
                'lens' => [
                    'make' => $media->getLensMake(),
                    'model' => $media->getLensModel(),
                    'specification' => $media->getLensSpecification(),
                    'serial' => $media->getLensSerialNumber(),
                ],
                'exposure' => [
                    'focal_length_mm' => $media->getFocalLengthMm(),
                    'focal_length_35mm' => $media->getFocalLength35mm(),
                    'aperture_f' => $media->getApertureF(),
                    'exposure_time_s' => $media->getExposureTimeS(),
                    'iso' => $media->getIso(),
                    'flash_fired' => $media->isFlashFired(),
                    'composite_image' => $media->getCompositeImage(),
                    'composite_source_count' => $media->getCompositeImageSourceCount(),
                    'composite_exposure_times' => $media->getCompositeImageExposureTimes(),
                ],
            ],
            'classification' => [
                'content_kind' => $media->getContentKind()?->value,
                'features' => $media->getFeatures(),
                'scene_tags' => $media->getSceneTags(),
                'has_faces' => $media->hasFaces(),
                'faces_count' => $media->getFacesCount(),
            ],
            'quality' => [
                'score' => $media->getQualityScore(),
                'exposure' => $media->getQualityExposure(),
                'noise' => $media->getQualityNoise(),
                'clipping' => $media->getQualityClipping(),
                'low_quality' => $media->isLowQuality(),
            ],
            'hashes' => [
                'checksum_sha256' => $media->getChecksum(),
                'fast_checksum_xxhash64' => $media->getFastChecksumXxhash64(),
                'phash' => $media->getPhash(),
                'phash64' => $media->getPhash64(),
                'phash_prefix' => $media->getPhashPrefix(),
                'live_pair_checksum' => $media->getLivePairChecksum(),
            ],
            'relationships' => [
                'burst' => [
                    'uuid' => $media->getBurstUuid(),
                    'index' => $media->getBurstIndex(),
                    'representative' => $media->isBurstRepresentative(),
                ],
                'live_pair_media_id' => $media->getLivePairMedia()?->getId(),
            ],
            'thumbnails' => $media->getThumbnails(),
            'qa_findings' => $this->serialiseQaFindings($qaFindings),
        ];
    }

    private function serialiseLocation(?Location $location): ?array
    {
        if (!$location instanceof Location) {
            return null;
        }

        return [
            'provider' => $location->getProvider(),
            'provider_place_id' => $location->getProviderPlaceId(),
            'display_name' => $location->getDisplayName(),
            'lat' => $location->getLat(),
            'lon' => $location->getLon(),
            'cell' => $location->getCell(),
            'country_code' => $location->getCountryCode(),
            'country' => $location->getCountry(),
            'state' => $location->getState(),
            'county' => $location->getCounty(),
            'city' => $location->getCity(),
            'suburb' => $location->getSuburb(),
            'postcode' => $location->getPostcode(),
            'road' => $location->getRoad(),
            'house_number' => $location->getHouseNumber(),
            'category' => $location->getCategory(),
            'type' => $location->getType(),
            'bounding_box' => $location->getBoundingBox(),
            'pois' => $location->getPois(),
            'attribution' => $location->getAttribution(),
            'licence' => $location->getLicence(),
            'refreshed_at' => $this->formatDate($location->getRefreshedAt()),
            'stale' => $location->isStale(),
            'confidence' => $location->getConfidence(),
            'accuracy_radius_meters' => $location->getAccuracyRadiusMeters(),
            'timezone' => $location->getTimezone(),
            'osm_type' => $location->getOsmType(),
        ];
    }

    /**
     * @param list<MetadataQaInspectionResult> $findings
     *
     * @return list<array{missing_features: list<string>, suggestions: list<string>}>
     */
    private function serialiseQaFindings(array $findings): array
    {
        $results = [];

        foreach ($findings as $finding) {
            if ($finding->hasIssues() === false) {
                continue;
            }

            $results[] = [
                'missing_features' => $finding->getMissingFeatures(),
                'suggestions' => $finding->getSuggestions(),
            ];
        }

        return $results;
    }

    private function formatDate(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }

    private function dumpFile(string $path, string $contents): void
    {
        $lastError = null;
        set_error_handler(
            static function (int $severity, string $message) use (&$lastError): bool {
                $lastError = $message;

                return true;
            }
        );

        try {
            $written = file_put_contents($path, $contents, LOCK_EX);
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            throw new RuntimeException(sprintf('Unable to write meta export file "%s": %s', $path, $lastError ?? 'unknown error'));
        }
    }
}
