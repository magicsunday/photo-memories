<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing\Stage;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\MetaExportStage;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Service\Metadata\MetadataQaInspectionResult;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;

use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_dir;
use function json_decode;
use function mkdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function rmdir;

use const JSON_THROW_ON_ERROR;

final class MetaExportStageTest extends TestCase
{
    #[Test]
    public function itExportsSignalsIntoTypedJsonPayload(): void
    {
        $baseDir = sys_get_temp_dir() . '/meta-export-' . uniqid('', true);
        $created = mkdir($baseDir, 0777, true);
        self::assertTrue($created, 'Failed to create temporary export directory.');

        try {
            $filePath = $baseDir . '/sample.jpg';
            file_put_contents($filePath, 'sample-binary');

            $checksum = (string) hash('sha256', 'sample-binary');
            $media    = new Media($filePath, $checksum, 13);
            $media->setFastChecksumXxhash64('feedfacecafebeef');
            $media->setMime('image/jpeg');
            $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
            $media->setIndexedAt(new DateTimeImmutable('2024-10-05T12:34:56+00:00'));
            $media->setIsVideo(true);
            $media->setIsRaw(true);
            $media->setIsHeic(false);
            $media->setIsHevc(false);
            $media->setNeedsRotation(true);
            $media->setNeedsGeocode(true);
            $media->setNoShow(true);
            $media->setVideoDurationS(12.34);
            $media->setVideoFps(29.97);
            $media->setVideoCodec('h264');
            $media->setVideoStreams([
                [
                    'index' => 0,
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'avg_frame_rate' => '30000/1001',
                ],
            ]);
            $media->setVideoRotationDeg(90.0);
            $media->setVideoHasStabilization(true);
            $media->setIsSlowMo(false);
            $media->setTakenAt(new DateTimeImmutable('2024-10-05T12:34:56+00:00'));
            $media->setCapturedLocal(new DateTimeImmutable('2024-10-05T14:34:56+02:00'));
            $media->setTimeSource(TimeSource::EXIF);
            $media->setTzId('Europe/Berlin');
            $media->setTzConfidence(0.85);
            $media->setTimezoneOffsetMin(120);
            $media->setSubSecOriginal(123456);
            $media->setWidth(4000);
            $media->setHeight(3000);
            $media->setOrientation(1);
            $media->setGpsLat(48.137154);
            $media->setGpsLon(11.576124);
            $media->setGpsAlt(520.5);
            $media->setGpsAccuracyM(4.2);
            $media->setGpsHeadingDeg(180.0);
            $media->setGpsSpeedMps(3.25);
            $media->setGeoCell8('u281zzzz');
            $media->setS2CellId('47c9');
            $media->setGeohash7('u281zzd');
            $media->setGeohash5('u281z');
            $media->setDistanceKmFromHome(12.6);
            $media->setHomeConfigHash('home_config_v2');
            $media->setCameraMake('Canon');
            $media->setCameraModel('EOS R6');
            $media->setCameraOwner('Alex');
            $media->setCameraBodySerial('123456789');
            $media->setLensMake('Canon');
            $media->setLensModel('RF 24-70mm');
            $media->setLensSpecification('24-70mm f/2.8');
            $media->setLensSerialNumber('LNS-0001');
            $media->setFocalLengthMm(35.0);
            $media->setFocalLength35mm(35);
            $media->setApertureF(2.8);
            $media->setExposureTimeS(0.01);
            $media->setIso(200);
            $media->setFlashFired(false);
            $media->setCompositeImage(1);
            $media->setCompositeImageSourceCount(3);
            $media->setCompositeImageExposureTimes('1/200,1/400');
            $media->setContentKind(ContentKind::PHOTO);
            $media->setFeatures([
                'calendar' => ['daypart' => 'evening', 'dow' => 6],
                'classification' => ['kind' => 'photo', 'confidence' => 0.91],
            ]);
            $media->setSceneTags([
                ['label' => 'sunset', 'score' => 0.88],
                ['label' => 'city', 'score' => 0.72],
            ]);
            $media->setHasFaces(true);
            $media->setFacesCount(2);
            $media->setSharpness(0.78);
            $media->setBrightness(0.62);
            $media->setContrast(0.55);
            $media->setEntropy(0.68);
            $media->setMotionBlurScore(0.81);
            $media->setColorfulness(0.73);
            $media->setQualityScore(0.82);
            $media->setQualityExposure(0.75);
            $media->setQualityNoise(0.33);
            $media->setQualityClipping(0.05);
            $media->setPhash('abcdef1234567890fedcba0987654321');
            $media->setPhash64('1234567890123456');
            $media->setPhashPrefix('abcdef1234567890');
            $media->setLivePairChecksum('livepairchecksum');
            $media->setBurstUuid('burst-uuid');
            $media->setBurstIndex(4);
            $media->setBurstRepresentative(true);
            $media->setThumbnails(['medium' => '/thumbnails/sample-medium.jpg']);
            $media->setIndexLog('previous');

            $location = new Location('nominatim', 'place-1', 'Marienplatz, München', 48.137154, 11.576124, 'u281zzzz');
            $location->setCountryCode('DE');
            $location->setCountry('Deutschland');
            $location->setState('Bayern');
            $location->setCounty('Oberbayern');
            $location->setCity('München');
            $location->setSuburb('Altstadt');
            $location->setPostcode('80331');
            $location->setRoad('Marienplatz');
            $location->setHouseNumber('1');
            $location->setCategory('tourism');
            $location->setType('attraction');
            $location->setBoundingBox([48.1368, 11.5758, 48.1375, 11.5766]);
            $location->setPois([
                ['name' => 'Rathaus', 'category' => 'historic'],
            ]);
            $location->setAttribution('© OpenStreetMap');
            $location->setLicence('ODbL');
            $location->setRefreshedAt(new DateTimeImmutable('2024-09-01T08:00:00+00:00'));
            $location->setStale(false);
            $location->setConfidence(0.87);
            $location->setAccuracyRadiusMeters(35.0);
            $location->setTimezone('Europe/Berlin');
            $location->setOsmType('node');

            $media->setLocation($location);
            $media->setPlaceIdPayload([
                'provider' => 'staypoint',
                'id' => 'stay-42',
                'confidence' => 0.76,
                'meta' => ['label' => 'Innenstadt'],
            ]);

            $context = MediaIngestionContext::create($filePath, false, false, false, false, new NullOutput());
            $context = $context->withMedia($media);
            $context = $context->withQaFinding(
                MetadataQaInspectionResult::withIssues(['calendar.daypart'], ['Check timezone votes'])
            );

            $stage = new MetaExportStage();
            $stage->process($context);

            $metaPath = $baseDir . '/media_index.meta';
            self::assertFileExists($metaPath);

            $contents = file_get_contents($metaPath);
            self::assertNotFalse($contents);

            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(1, $payload['schema_version']);
            self::assertSame($media->getChecksum(), $payload['identity']['checksum_sha256']);
            self::assertSame('image/jpeg', $payload['identity']['mime']);
            self::assertTrue($payload['flags']['is_video']);
            self::assertSame('EXIF', $payload['capture']['time_source']);
            self::assertSame(48.137154, $payload['spatial']['gps']['lat']);
            self::assertSame('staypoint', $payload['spatial']['place']['provider']);
            self::assertSame('Canon', $payload['imaging']['camera']['make']);
            self::assertSame('photo', $payload['classification']['content_kind']);
            self::assertSame(['medium' => '/thumbnails/sample-medium.jpg'], $payload['thumbnails']);
            self::assertSame(
                [
                    'video_duration_s' => 12.34,
                    'video_fps' => 29.97,
                    'video_codec' => 'h264',
                    'video_streams' => [
                        [
                            'index' => 0,
                            'codec_type' => 'video',
                            'codec_name' => 'h264',
                            'avg_frame_rate' => '30000/1001',
                        ],
                    ],
                    'video_rotation_deg' => 90.0,
                    'video_has_stabilization' => true,
                    'is_slow_mo' => false,
                ],
                $payload['video']
            );
            self::assertSame(
                [
                    'sharpness' => 0.78,
                    'brightness' => 0.62,
                    'contrast' => 0.55,
                    'entropy' => 0.68,
                    'motion_blur_score' => 0.81,
                    'colorfulness' => 0.73,
                ],
                $payload['quality_proxies']
            );
            self::assertSame(
                [
                    [
                        'missing_features' => ['calendar.daypart'],
                        'suggestions' => ['Check timezone votes'],
                    ],
                ],
                $payload['qa_findings']
            );
        } finally {
            $this->removeDirectory($baseDir);
        }
    }

    #[Test]
    public function itSkipsExportWhenRunningInDryRunMode(): void
    {
        $baseDir = sys_get_temp_dir() . '/meta-export-' . uniqid('', true);
        $created = mkdir($baseDir, 0777, true);
        self::assertTrue($created, 'Failed to create temporary export directory.');

        try {
            $filePath = $baseDir . '/sample.jpg';
            file_put_contents($filePath, 'sample-binary');

            $checksum = (string) hash('sha256', 'sample-binary');
            $media    = new Media($filePath, $checksum, 13);

            $context = MediaIngestionContext::create($filePath, false, true, false, false, new NullOutput());
            $context = $context->withMedia($media);

            $stage = new MetaExportStage();
            $stage->process($context);

            self::assertFileDoesNotExist($baseDir . '/media_index.meta');
        } finally {
            $this->removeDirectory($baseDir);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (is_dir($directory) === false) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
