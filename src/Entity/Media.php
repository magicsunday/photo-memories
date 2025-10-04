<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MagicSunday\Memories\Entity\Enum\TimeSource;

/**
 * Doctrine entity describing an imported photo or video including its metadata.
 */
#[ORM\Entity]
#[ORM\Table(name: 'media')]
#[ORM\Index(name: 'idx_taken_at', fields: ['takenAt'])]
#[ORM\Index(name: 'idx_checksum', fields: ['checksum'])]
class Media
{
    /**
     * Internal primary key managed by the database sequence.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    /**
     * Absolute path to the media asset within the library.
     */
    #[ORM\Column(type: Types::STRING, length: 2048, unique: true)]
    private string $path;

    /**
     * Hash representing the binary payload for duplicate detection.
     */
    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $checksum;

    /**
     * File size in bytes.
     */
    #[ORM\Column(type: Types::BIGINT)]
    private int $size;

    /**
     * Timestamp when the media entity was created in the catalogue.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * MIME type describing the media payload (for example image/jpeg).
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $mime = null;

    /**
     * Original capture timestamp extracted from EXIF or file metadata.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $takenAt = null;

    /**
     * Source system that provided the capture timestamp.
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: TimeSource::class)]
    private ?TimeSource $timeSource = null;

    /**
     * Timezone identifier associated with the capture event.
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $tzId = null;

    /**
     * Capture timestamp expressed in local wall time.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $capturedLocal = null;

    /**
     * Timezone offset in minutes derived from the capture moment.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $timezoneOffsetMin = null;

    /**
     * Pixel width of the media.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    /**
     * Pixel height of the media.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    /**
     * Latitude coordinate captured by the device in decimal degrees.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsLat = null;

    /**
     * Longitude coordinate captured by the device in decimal degrees.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsLon = null;

    /**
     * Camera model responsible for capturing the media.
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $cameraModel = null;

    /**
     * Camera manufacturer name.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $cameraMake = null;

    /**
     * Registered camera owner stored in the EXIF metadata.
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $cameraOwner = null;

    /**
     * Serial number of the camera body.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $cameraBodySerial = null;

    /**
     * Lens manufacturer name.
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $lensMake = null;

    /**
     * Lens model used during capture, if available.
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $lensModel = null;

    /**
     * Normalized lens specification range, for example 24-70mm f/2.8-4.
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $lensSpecification = null;

    /**
     * Serial number of the attached lens.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $lensSerialNumber = null;

    /**
     * Focal length in millimetres derived from metadata.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $focalLengthMm = null;

    /**
     * 35 mm equivalent focal length value.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $focalLength35mm = null;

    /**
     * Aperture value (f-stop) recorded at capture.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $apertureF = null;

    /**
     * Exposure duration in seconds.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $exposureTimeS = null;

    /**
     * Composite image indicator as defined by the EXIF specification.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $compositeImage = null;

    /**
     * Number of source images contributing to the composite capture.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $compositeImageSourceCount = null;

    /**
     * Exposure times of the source images composing the final media.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $compositeImageExposureTimes = null;

    /**
     * ISO sensitivity value.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $iso = null;

    /**
     * Boolean flag indicating whether the flash was fired.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $flashFired = null;

    /**
     * Orientation encoded according to EXIF standard.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $orientation = null;

    /**
     * Altitude relative to sea level in metres.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsAlt = null;

    /**
     * Horizontal accuracy of the GPS coordinates in metres.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsAccuracyM = null;

    /**
     * Heading direction in degrees.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsHeadingDeg = null;

    /**
     * Recorded speed in metres per second.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsSpeedMps = null;

    /**
     * GeoHash cell identifier at precision eight.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $geoCell8 = null;

    /**
     * Distance from configured home location in kilometres.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $distanceKmFromHome = null;

    /**
     * Identifier linking related burst captures.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $burstUuid = null;

    /**
     * Fractional capture seconds extracted from EXIF metadata.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $subSecOriginal = null;

    /**
     * Checksum of the paired live photo, if present.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $livePairChecksum = null;

    /**
     * Boolean flag indicating whether the media is a video clip.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isVideo = false;

    /**
     * Duration of the video in seconds.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $videoDurationS = null;

    /**
     * Frames per second for the video asset.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $videoFps = null;

    /**
     * Codec used for encoding the video.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $videoCodec = null;

    /**
     * Indicates whether the video was captured in slow motion.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isSlowMo = null;

    /**
     * Flag indicating whether the media is portrait oriented.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isPortrait = null;

    /**
     * Flag indicating whether the media represents a panorama capture.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isPanorama = null;

    /**
     * Calculated sharpness score used for quality metrics.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $sharpness = null;

    /**
     * Calculated brightness value used for quality metrics.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $brightness = null;

    /**
     * Calculated contrast value used for quality metrics.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $contrast = null;

    /**
     * Calculated entropy score representing detail complexity.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entropy = null;

    /**
     * Calculated colorfulness score representing saturation.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $colorfulness = null;

    /**
     * Prefix of the perceptual hash for quick similarity checks.
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $phashPrefix = null;

    /**
     * Complete perceptual hash of the media.
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $phash = null;

    /**
     * Difference hash of the media preview.
     */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $dhash = null;

    /**
     * Average hash of the media preview.
     */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $ahash = null;

    /**
     * List of keywords extracted or assigned to the media.
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keywords = null;

    /**
     * List of detected or tagged persons.
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $persons = null;

    /**
     * Feature set describing the scene (labels, categories, etc.).
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    /**
     * Generated thumbnails mapped by size identifier.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $thumbnails = null;

    /**
     * Optional location entity representing the geocoded place.
     */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    /**
     * @param string $path     Absolute filesystem path to the asset.
     * @param string $checksum Binary hash for deduplication.
     * @param int    $size     File size in bytes.
     */
    public function __construct(string $path, string $checksum, int $size)
    {
        $this->path      = $path;
        $this->checksum  = $checksum;
        $this->size      = $size;

        // Use the current timestamp to record when the media was first catalogued.
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Returns the unique identifier assigned by Doctrine.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Provides the absolute filesystem path to the media asset.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Returns the checksum used for duplicate detection.
     */
    public function getChecksum(): string
    {
        return $this->checksum;
    }

    /**
     * Returns the stored file size in bytes.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Returns the timestamp when this entity was created.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Retrieves the MIME type of the media.
     */
    public function getMime(): ?string
    {
        return $this->mime;
    }

    /**
     * Sets the MIME type of the media.
     *
     * @param string|null $mime MIME type (for example image/jpeg).
     */
    public function setMime(?string $mime): void
    {
        $this->mime = $mime;
    }

    /**
     * Retrieves the capture timestamp of the media.
     */
    public function getTakenAt(): ?DateTimeImmutable
    {
        return $this->takenAt;
    }

    /**
     * Sets the capture timestamp of the media.
     *
     * @param DateTimeImmutable|null $takenAt Capture time from metadata.
     */
    public function setTakenAt(?DateTimeImmutable $takenAt): void
    {
        $this->takenAt = $takenAt;
    }

    /**
     * Returns the origin of the capture timestamp.
     */
    public function getTimeSource(): ?TimeSource
    {
        return $this->timeSource;
    }

    /**
     * Sets the origin of the capture timestamp.
     */
    public function setTimeSource(?TimeSource $timeSource): void
    {
        $this->timeSource = $timeSource;
    }

    /**
     * Returns the timezone identifier of the capture event.
     */
    public function getTzId(): ?string
    {
        return $this->tzId;
    }

    /**
     * Sets the timezone identifier of the capture event.
     */
    public function setTzId(?string $tzId): void
    {
        $this->tzId = $tzId;
    }

    /**
     * Returns the capture timestamp expressed in local wall time.
     */
    public function getCapturedLocal(): ?DateTimeImmutable
    {
        return $this->capturedLocal;
    }

    /**
     * Sets the capture timestamp expressed in local wall time.
     */
    public function setCapturedLocal(?DateTimeImmutable $capturedLocal): void
    {
        $this->capturedLocal = $capturedLocal;
    }

    /**
     * Returns the pixel width of the media.
     */
    public function getWidth(): ?int
    {
        return $this->width;
    }

    /**
     * Sets the pixel width of the media.
     *
     * @param int|null $width Width in pixels.
     */
    public function setWidth(?int $width): void
    {
        $this->width = $width;
    }

    /**
     * Returns the pixel height of the media.
     */
    public function getHeight(): ?int
    {
        return $this->height;
    }

    /**
     * Sets the pixel height of the media.
     *
     * @param int|null $height Height in pixels.
     */
    public function setHeight(?int $height): void
    {
        $this->height = $height;
    }

    /**
     * Returns the latitude coordinate.
     */
    public function getGpsLat(): ?float
    {
        return $this->gpsLat;
    }

    /**
     * Sets the latitude coordinate.
     *
     * @param float|null $gpsLat Latitude in decimal degrees.
     */
    public function setGpsLat(?float $gpsLat): void
    {
        $this->gpsLat = $gpsLat;
    }

    /**
     * Returns the longitude coordinate.
     */
    public function getGpsLon(): ?float
    {
        return $this->gpsLon;
    }

    /**
     * Sets the longitude coordinate.
     *
     * @param float|null $gpsLon Longitude in decimal degrees.
     */
    public function setGpsLon(?float $gpsLon): void
    {
        $this->gpsLon = $gpsLon;
    }

    /**
     * Returns the camera model.
     */
    public function getCameraModel(): ?string
    {
        return $this->cameraModel;
    }

    /**
     * Sets the camera model.
     *
     * @param string|null $cameraModel Camera model name.
     */
    public function setCameraModel(?string $cameraModel): void
    {
        $this->cameraModel = $cameraModel;
    }

    /**
     * Retrieves the perceptual hash of the media.
     */
    public function getPhash(): ?string
    {
        return $this->phash;
    }

    /**
     * Stores the perceptual hash of the media.
     *
     * @param string|null $phash Perceptual hash value.
     */
    public function setPhash(?string $phash): void
    {
        $this->phash = $phash;
    }

    /**
     * Returns the generated thumbnails mapping.
     *
     * @return array<string, string>|null
     */
    public function getThumbnails(): ?array
    {
        return $this->thumbnails;
    }

    /**
     * Sets the generated thumbnails mapping.
     *
     * @param array<string, string>|null $thumbnails Thumbnails indexed by label.
     */
    public function setThumbnails(?array $thumbnails): void
    {
        $this->thumbnails = $thumbnails;
    }

    /**
     * Returns the timezone offset in minutes.
     */
    public function getTimezoneOffsetMin(): ?int
    {
        return $this->timezoneOffsetMin;
    }

    /**
     * Sets the timezone offset in minutes.
     *
     * @param int|null $v Offset in minutes relative to UTC.
     */
    public function setTimezoneOffsetMin(?int $v): void
    {
        $this->timezoneOffsetMin = $v;
    }

    /**
     * Returns the EXIF orientation value.
     */
    public function getOrientation(): ?int
    {
        return $this->orientation;
    }

    /**
     * Sets the EXIF orientation value.
     *
     * @param int|null $v EXIF orientation.
     */
    public function setOrientation(?int $v): void
    {
        $this->orientation = $v;
    }

    /**
     * Returns the camera manufacturer name.
     */
    public function getCameraMake(): ?string
    {
        return $this->cameraMake;
    }

    /**
     * Sets the camera manufacturer name.
     *
     * @param string|null $v Manufacturer name.
     */
    public function setCameraMake(?string $v): void
    {
        $this->cameraMake = $v;
    }

    /**
     * Returns the registered camera owner.
     */
    public function getCameraOwner(): ?string
    {
        return $this->cameraOwner;
    }

    /**
     * Sets the registered camera owner.
     *
     * @param string|null $v Camera owner name.
     */
    public function setCameraOwner(?string $v): void
    {
        $this->cameraOwner = $v;
    }

    /**
     * Returns the camera body serial number.
     */
    public function getCameraBodySerial(): ?string
    {
        return $this->cameraBodySerial;
    }

    /**
     * Sets the camera body serial number.
     *
     * @param string|null $v Serial number string.
     */
    public function setCameraBodySerial(?string $v): void
    {
        $this->cameraBodySerial = $v;
    }

    /**
     * Returns the lens manufacturer name.
     */
    public function getLensMake(): ?string
    {
        return $this->lensMake;
    }

    /**
     * Sets the lens manufacturer name.
     *
     * @param string|null $v Lens manufacturer name.
     */
    public function setLensMake(?string $v): void
    {
        $this->lensMake = $v;
    }

    /**
     * Returns the lens model information.
     */
    public function getLensModel(): ?string
    {
        return $this->lensModel;
    }

    /**
     * Sets the lens model information.
     *
     * @param string|null $v Lens model name.
     */
    public function setLensModel(?string $v): void
    {
        $this->lensModel = $v;
    }

    /**
     * Returns the normalized lens specification.
     */
    public function getLensSpecification(): ?string
    {
        return $this->lensSpecification;
    }

    /**
     * Sets the normalized lens specification.
     *
     * @param string|null $v Lens specification description.
     */
    public function setLensSpecification(?string $v): void
    {
        $this->lensSpecification = $v;
    }

    /**
     * Returns the lens serial number.
     */
    public function getLensSerialNumber(): ?string
    {
        return $this->lensSerialNumber;
    }

    /**
     * Sets the lens serial number.
     *
     * @param string|null $v Serial number string.
     */
    public function setLensSerialNumber(?string $v): void
    {
        $this->lensSerialNumber = $v;
    }

    /**
     * Returns the focal length in millimetres.
     */
    public function getFocalLengthMm(): ?float
    {
        return $this->focalLengthMm;
    }

    /**
     * Sets the focal length in millimetres.
     *
     * @param float|null $v Focal length in millimetres.
     */
    public function setFocalLengthMm(?float $v): void
    {
        $this->focalLengthMm = $v;
    }

    /**
     * Returns the 35 mm equivalent focal length.
     */
    public function getFocalLength35mm(): ?int
    {
        return $this->focalLength35mm;
    }

    /**
     * Sets the 35 mm equivalent focal length.
     *
     * @param int|null $v Focal length in 35 mm equivalent.
     */
    public function setFocalLength35mm(?int $v): void
    {
        $this->focalLength35mm = $v;
    }

    /**
     * Returns the aperture value.
     */
    public function getApertureF(): ?float
    {
        return $this->apertureF;
    }

    /**
     * Sets the aperture value.
     *
     * @param float|null $v Aperture value (f-stop).
     */
    public function setApertureF(?float $v): void
    {
        $this->apertureF = $v;
    }

    /**
     * Returns the exposure time in seconds.
     */
    public function getExposureTimeS(): ?float
    {
        return $this->exposureTimeS;
    }

    /**
     * Sets the exposure time in seconds.
     *
     * @param float|null $v Exposure time in seconds.
     */
    public function setExposureTimeS(?float $v): void
    {
        $this->exposureTimeS = $v;
    }

    /**
     * Returns the composite image flag value.
     */
    public function getCompositeImage(): ?int
    {
        return $this->compositeImage;
    }

    /**
     * Sets the composite image flag value.
     *
     * @param int|null $v Composite image indicator.
     */
    public function setCompositeImage(?int $v): void
    {
        $this->compositeImage = $v;
    }

    /**
     * Returns the number of source images for the composite capture.
     */
    public function getCompositeImageSourceCount(): ?int
    {
        return $this->compositeImageSourceCount;
    }

    /**
     * Sets the number of source images for the composite capture.
     *
     * @param int|null $v Source image count.
     */
    public function setCompositeImageSourceCount(?int $v): void
    {
        $this->compositeImageSourceCount = $v;
    }

    /**
     * Returns the recorded source exposure times for the composite.
     */
    public function getCompositeImageExposureTimes(): ?string
    {
        return $this->compositeImageExposureTimes;
    }

    /**
     * Sets the recorded source exposure times for the composite.
     *
     * @param string|null $v Exposure times description.
     */
    public function setCompositeImageExposureTimes(?string $v): void
    {
        $this->compositeImageExposureTimes = $v;
    }

    /**
     * Returns the ISO sensitivity value.
     */
    public function getIso(): ?int
    {
        return $this->iso;
    }

    /**
     * Sets the ISO sensitivity value.
     *
     * @param int|null $v ISO value.
     */
    public function setIso(?int $v): void
    {
        $this->iso = $v;
    }

    /**
     * Indicates whether the flash fired during capture.
     */
    public function isFlashFired(): ?bool
    {
        return $this->flashFired;
    }

    /**
     * Updates the flash fired flag.
     *
     * @param bool|null $v Whether the flash fired.
     */
    public function setFlashFired(?bool $v): void
    {
        $this->flashFired = $v;
    }

    /**
     * Returns the GPS altitude in metres.
     */
    public function getGpsAlt(): ?float
    {
        return $this->gpsAlt;
    }

    /**
     * Sets the GPS altitude in metres.
     *
     * @param float|null $v Altitude in metres.
     */
    public function setGpsAlt(?float $v): void
    {
        $this->gpsAlt = $v;
    }

    /**
     * Returns the GPS accuracy in metres.
     */
    public function getGpsAccuracyM(): ?float
    {
        return $this->gpsAccuracyM;
    }

    /**
     * Sets the GPS accuracy in metres.
     *
     * @param float|null $v Accuracy in metres.
     */
    public function setGpsAccuracyM(?float $v): void
    {
        $this->gpsAccuracyM = $v;
    }

    /**
     * Returns the heading direction in degrees.
     */
    public function getGpsHeadingDeg(): ?float
    {
        return $this->gpsHeadingDeg;
    }

    /**
     * Sets the heading direction in degrees.
     *
     * @param float|null $v Heading in degrees.
     */
    public function setGpsHeadingDeg(?float $v): void
    {
        $this->gpsHeadingDeg = $v;
    }

    /**
     * Returns the speed in metres per second.
     */
    public function getGpsSpeedMps(): ?float
    {
        return $this->gpsSpeedMps;
    }

    /**
     * Sets the speed in metres per second.
     *
     * @param float|null $v Speed in metres per second.
     */
    public function setGpsSpeedMps(?float $v): void
    {
        $this->gpsSpeedMps = $v;
    }

    /**
     * Returns the GeoHash cell identifier.
     */
    public function getGeoCell8(): ?string
    {
        return $this->geoCell8;
    }

    /**
     * Sets the GeoHash cell identifier.
     *
     * @param string|null $v GeoHash string with eight characters.
     */
    public function setGeoCell8(?string $v): void
    {
        $this->geoCell8 = $v;
    }

    /**
     * Returns the distance from home in kilometres.
     */
    public function getDistanceKmFromHome(): ?float
    {
        return $this->distanceKmFromHome;
    }

    /**
     * Sets the distance from home in kilometres.
     *
     * @param float|null $v Distance in kilometres.
     */
    public function setDistanceKmFromHome(?float $v): void
    {
        $this->distanceKmFromHome = $v;
    }

    /**
     * Returns the burst identifier.
     */
    public function getBurstUuid(): ?string
    {
        return $this->burstUuid;
    }

    /**
     * Sets the burst identifier.
     *
     * @param string|null $v Burst UUID shared across frames.
     */
    public function setBurstUuid(?string $v): void
    {
        $this->burstUuid = $v;
    }

    /**
     * Returns the sub-second capture component.
     */
    public function getSubSecOriginal(): ?int
    {
        return $this->subSecOriginal;
    }

    /**
     * Sets the sub-second capture component.
     *
     * @param int|null $v Fractional seconds of capture time.
     */
    public function setSubSecOriginal(?int $v): void
    {
        $this->subSecOriginal = $v;
    }

    /**
     * Returns the checksum of the paired live photo.
     */
    public function getLivePairChecksum(): ?string
    {
        return $this->livePairChecksum;
    }

    /**
     * Sets the checksum of the paired live photo.
     *
     * @param string|null $v Checksum of the linked live photo.
     */
    public function setLivePairChecksum(?string $v): void
    {
        $this->livePairChecksum = $v;
    }

    /**
     * Indicates whether the media is a video.
     */
    public function isVideo(): bool
    {
        return $this->isVideo;
    }

    /**
     * Sets whether the media is a video.
     *
     * @param bool $v True if the media is a video.
     */
    public function setIsVideo(bool $v): void
    {
        $this->isVideo = $v;
    }

    /**
     * Returns the video duration in seconds.
     */
    public function getVideoDurationS(): ?float
    {
        return $this->videoDurationS;
    }

    /**
     * Sets the video duration in seconds.
     *
     * @param float|null $v Video duration in seconds.
     */
    public function setVideoDurationS(?float $v): void
    {
        $this->videoDurationS = $v;
    }

    /**
     * Returns the video frame rate.
     */
    public function getVideoFps(): ?float
    {
        return $this->videoFps;
    }

    /**
     * Sets the video frame rate.
     *
     * @param float|null $v Video frames per second.
     */
    public function setVideoFps(?float $v): void
    {
        $this->videoFps = $v;
    }

    /**
     * Returns the video codec name.
     */
    public function getVideoCodec(): ?string
    {
        return $this->videoCodec;
    }

    /**
     * Sets the video codec name.
     *
     * @param string|null $v Video codec identifier.
     */
    public function setVideoCodec(?string $v): void
    {
        $this->videoCodec = $v;
    }

    /**
     * Indicates whether the video is a slow motion clip.
     */
    public function isSlowMo(): ?bool
    {
        return $this->isSlowMo;
    }

    /**
     * Sets whether the video is a slow motion clip.
     *
     * @param bool|null $v True when the video is slow motion.
     */
    public function setIsSlowMo(?bool $v): void
    {
        $this->isSlowMo = $v;
    }

    /**
     * Indicates whether the media is portrait oriented.
     */
    public function isPortrait(): ?bool
    {
        return $this->isPortrait;
    }

    /**
     * Sets whether the media is portrait oriented.
     *
     * @param bool|null $v True if the media is portrait.
     */
    public function setIsPortrait(?bool $v): void
    {
        $this->isPortrait = $v;
    }

    /**
     * Indicates whether the media is a panorama capture.
     */
    public function isPanorama(): ?bool
    {
        return $this->isPanorama;
    }

    /**
     * Sets whether the media is a panorama capture.
     *
     * @param bool|null $v True if the media is a panorama.
     */
    public function setIsPanorama(?bool $v): void
    {
        $this->isPanorama = $v;
    }

    /**
     * Returns the calculated sharpness score.
     */
    public function getSharpness(): ?float
    {
        return $this->sharpness;
    }

    /**
     * Sets the calculated sharpness score.
     *
     * @param float|null $v Sharpness score.
     */
    public function setSharpness(?float $v): void
    {
        $this->sharpness = $v;
    }

    /**
     * Returns the calculated brightness value.
     */
    public function getBrightness(): ?float
    {
        return $this->brightness;
    }

    /**
     * Sets the calculated brightness value.
     *
     * @param float|null $v Brightness value.
     */
    public function setBrightness(?float $v): void
    {
        $this->brightness = $v;
    }

    /**
     * Returns the calculated contrast value.
     */
    public function getContrast(): ?float
    {
        return $this->contrast;
    }

    /**
     * Sets the calculated contrast value.
     *
     * @param float|null $v Contrast value.
     */
    public function setContrast(?float $v): void
    {
        $this->contrast = $v;
    }

    /**
     * Returns the calculated entropy score.
     */
    public function getEntropy(): ?float
    {
        return $this->entropy;
    }

    /**
     * Sets the calculated entropy score.
     *
     * @param float|null $v Entropy score.
     */
    public function setEntropy(?float $v): void
    {
        $this->entropy = $v;
    }

    /**
     * Returns the calculated colorfulness score.
     */
    public function getColorfulness(): ?float
    {
        return $this->colorfulness;
    }

    /**
     * Sets the calculated colorfulness score.
     *
     * @param float|null $v Colorfulness score.
     */
    public function setColorfulness(?float $v): void
    {
        $this->colorfulness = $v;
    }

    /**
     * Returns the perceptual hash prefix.
     */
    public function getPhashPrefix(): ?string
    {
        return $this->phashPrefix;
    }

    /**
     * Sets the perceptual hash prefix.
     *
     * @param string|null $v Prefix of the perceptual hash.
     */
    public function setPhashPrefix(?string $v): void
    {
        $this->phashPrefix = $v;
    }

    /**
     * Returns the difference hash.
     */
    public function getDhash(): ?string
    {
        return $this->dhash;
    }

    /**
     * Sets the difference hash.
     *
     * @param string|null $v Difference hash value.
     */
    public function setDhash(?string $v): void
    {
        $this->dhash = $v;
    }

    /**
     * Returns the average hash.
     */
    public function getAhash(): ?string
    {
        return $this->ahash;
    }

    /**
     * Sets the average hash.
     *
     * @param string|null $v Average hash value.
     */
    public function setAhash(?string $v): void
    {
        $this->ahash = $v;
    }

    /**
     * Returns the keyword list.
     *
     * @return array<int, string>|null
     */
    public function getKeywords(): ?array
    {
        return $this->keywords;
    }

    /**
     * Sets the keyword list.
     *
     * @param array<int, string>|null $v Keywords describing the media.
     */
    public function setKeywords(?array $v): void
    {
        $this->keywords = $v;
    }

    /**
     * Returns the list of persons.
     *
     * @return array<int, string>|null
     */
    public function getPersons(): ?array
    {
        return $this->persons;
    }

    /**
     * Sets the list of persons.
     *
     * @param array<int, string>|null $v Persons associated with the media.
     */
    public function setPersons(?array $v): void
    {
        $this->persons = $v;
    }

    /**
     * Returns the feature list.
     *
     * @return array<int, string>|null
     */
    public function getFeatures(): ?array
    {
        return $this->features;
    }

    /**
     * Sets the feature list.
     *
     * @param array<int, string>|null $v Features describing the scene.
     */
    public function setFeatures(?array $v): void
    {
        $this->features = $v;
    }

    /**
     * Returns the associated location entity.
     */
    public function getLocation(): ?Location
    {
        return $this->location;
    }

    /**
     * Associates a location entity with the media.
     *
     * @param Location|null $location Geocoded location for the capture.
     */
    public function setLocation(?Location $location): void
    {
        $this->location = $location;
    }
}
