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
use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use function count;
use function max;
use function min;

/**
 * Doctrine entity describing an imported photo or video including its metadata.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'media',
    indexes: [
        new ORM\Index(name: 'idx_taken_at', columns: ['takenAt']),
        new ORM\Index(name: 'idx_checksum', columns: ['checksum']),
        new ORM\Index(name: 'idx_fast_checksum_xxhash64', columns: ['fastChecksumXxhash64']),
        new ORM\Index(name: 'idx_phash64', columns: ['phash64']),
        new ORM\Index(name: 'idx_live_pair_checksum', columns: ['livePairChecksum']),
        new ORM\Index(name: 'idx_media_live_pair_id', columns: ['livePairMediaId']),
        new ORM\Index(name: 'idx_media_geocell8', columns: ['geoCell8']),
        new ORM\Index(name: 'idx_media_geohash7', columns: ['geohash7']),
        new ORM\Index(name: 'idx_media_geohash5', columns: ['geohash5']),
        new ORM\Index(name: 'idx_media_phash_prefix', columns: ['phashPrefix']),
        new ORM\Index(name: 'idx_media_burst_taken', columns: ['burstUuid', 'takenAt']),
        new ORM\Index(name: 'idx_media_burst_repr', columns: ['burstUuid', 'burstRepresentative']),
        new ORM\Index(name: 'idx_media_video_taken', columns: ['isVideo', 'takenAt']),
        new ORM\Index(name: 'idx_media_location', columns: ['location_id']),
        new ORM\Index(name: 'idx_media_needs_geocode', columns: ['needsGeocode']),
        new ORM\Index(name: 'idx_media_candidate', columns: ['noShow', 'lowQuality', 'takenAt']),
    ]
)]
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
     * Fast hash representing the binary payload using the xxHash64 algorithm.
     */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $fastChecksumXxhash64 = null;

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
     * Metadata feature version used during the last extraction run.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $featureVersion = 0;

    /**
     * Timestamp when metadata extraction last ran for this media.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $indexedAt = null;

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
     * Confidence score (0..1) describing the accuracy of the timezone metadata.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $tzConfidence = null;

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
     * Indicates whether downstream consumers need to rotate the asset.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $needsRotation = false;

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
     * GeoHash at precision seven characters.
     */
    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    private ?string $geohash7 = null;

    /**
     * GeoHash at precision five characters.
     */
    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    private ?string $geohash5 = null;

    /**
     * Distance from configured home location in kilometres.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $distanceKmFromHome = null;

    /**
     * Hash describing the configuration used for home distance calculations.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $homeConfigHash = null;

    /**
     * Identifier linking related burst captures.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $burstUuid = null;

    /**
     * Sequential index of the media within its burst group.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $burstIndex = null;

    /**
     * Flag marking the canonical member of a burst sequence.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $burstRepresentative = null;

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
     * Linked media item that forms a live photo pair.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'livePairMediaId', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $livePairMedia = null;

    /**
     * Indicates whether the media represents a RAW capture.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isRaw = false;

    /**
     * Indicates whether the media container uses the HEIC format.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isHeic = false;

    /**
     * Indicates whether the media payload is encoded with HEVC.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isHevc = false;

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
     * Rotation derived from ffprobe metadata in degrees.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $videoRotationDeg = null;

    /**
     * Indicates whether stabilisation metadata is present for the video stream.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $videoHasStabilization = null;

    /**
     * Normalised ffprobe stream metadata for downstream consumers.
     *
     * @var array<int, array<int|string, int|float|string|bool|null|array<int|string, int|float|string|bool|null|array>>>|null $videoStreams
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $videoStreams = null;

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
     * Categorised content kind describing non-standard assets.
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: ContentKind::class)]
    private ?ContentKind $contentKind = null;

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
     * Share of pixels affected by saturation clipping in the range [0,1].
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $qualityClipping = null;

    /**
     * Aggregated quality score in the range [0,1].
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $qualityScore = null;

    /**
     * Aggregated exposure score in the range [0,1].
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $qualityExposure = null;

    /**
     * Aggregated noise score (higher means cleaner image).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $qualityNoise = null;

    /**
     * Flag indicating whether the media falls below quality thresholds.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $lowQuality = false;

    /**
     * Marker indicating whether the media should be hidden from downstream feeds.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $noShow = false;

    /**
     * Prefix of the perceptual hash for quick similarity checks.
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $phashPrefix = null;

    /**
     * Complete perceptual hash of the media (128-bit hexadecimal string).
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $phash = null;

    /**
     * High 64 bits of the perceptual hash represented as an unsigned integer string.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true, options: ['unsigned' => true])]
    private ?string $phash64 = null;

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
     * @var array<int, string>|null $keywords
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keywords = null;

    /**
     * List of detected or tagged persons.
     *
     * @var array<int, string>|null $persons
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $persons = null;

    /**
     * Flag indicating whether the media contains detected faces.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hasFaces = false;

    /**
     * Number of detected or tagged faces associated with the media.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $facesCount = 0;

    /**
     * Feature set describing the scene (labels, categories, etc.).
     *
     * @var array<string, scalar|array|null>|null $features
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    /**
     * Highest confidence scene tags derived from vision models.
     *
     * @var list<array{label: string, score: float}>|null $sceneTags
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $sceneTags = null;

    /**
     * Generated thumbnails mapped by size identifier.
     *
     * @var array<string, string>|null $thumbnails
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $thumbnails = null;

    /**
     * Log output of the last indexing run including errors.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $indexLog = null;

    /**
     * Indicates whether the media still requires geocoding.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $needsGeocode = false;

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
     * Returns the fast checksum generated via xxHash64.
     */
    public function getFastChecksumXxhash64(): ?string
    {
        return $this->fastChecksumXxhash64;
    }

    /**
     * Updates the fast checksum generated via xxHash64.
     *
     * @param string|null $fastChecksumXxhash64 Fast checksum value.
     */
    public function setFastChecksumXxhash64(?string $fastChecksumXxhash64): void
    {
        $this->fastChecksumXxhash64 = $fastChecksumXxhash64;
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
     * Returns the metadata feature version used for the last extraction.
     */
    public function getFeatureVersion(): int
    {
        return $this->featureVersion;
    }

    /**
     * Updates the metadata feature version used for the last extraction.
     *
     * @param int $featureVersion Metadata feature schema version.
     */
    public function setFeatureVersion(int $featureVersion): void
    {
        $this->featureVersion = $featureVersion;
    }

    /**
     * Returns the timestamp of the most recent metadata extraction run.
     */
    public function getIndexedAt(): ?DateTimeImmutable
    {
        return $this->indexedAt;
    }

    /**
     * Updates the timestamp of the most recent metadata extraction run.
     *
     * @param DateTimeImmutable|null $indexedAt Extraction completion timestamp.
     */
    public function setIndexedAt(?DateTimeImmutable $indexedAt): void
    {
        $this->indexedAt = $indexedAt;
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
     * Returns the confidence score assigned to the timezone metadata.
     */
    public function getTzConfidence(): ?float
    {
        return $this->tzConfidence;
    }

    /**
     * Updates the confidence score assigned to the timezone metadata.
     *
     * @param float|null $tzConfidence Confidence level between 0.0 and 1.0.
     */
    public function setTzConfidence(?float $tzConfidence): void
    {
        if ($tzConfidence === null) {
            $this->tzConfidence = null;

            return;
        }

        $this->tzConfidence = max(0.0, min(1.0, $tzConfidence));
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
        if ($phash === null) {
            $this->phash = null;

            return;
        }

        $hash = strtolower($phash);
        $this->phash = substr($hash, 0, 32);
    }

    /**
     * Returns the perceptual hash as an unsigned 64-bit integer string.
     */
    public function getPhash64(): ?string
    {
        return $this->phash64;
    }

    /**
     * Stores the perceptual hash as an unsigned 64-bit integer string.
     *
     * @param string|null $value Unsigned integer representation of the pHash.
     */
    public function setPhash64(?string $value): void
    {
        $this->phash64 = $value;
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
     * Returns the log output of the last indexing run.
     */
    public function getIndexLog(): ?string
    {
        return $this->indexLog;
    }

    /**
     * Updates the log output of the last indexing run.
     *
     * @param string|null $indexLog Logged error details or null on success.
     */
    public function setIndexLog(?string $indexLog): void
    {
        $this->indexLog = $indexLog;
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
     * Indicates whether the stored asset requires rotation based on metadata.
     */
    public function needsRotation(): bool
    {
        return $this->needsRotation;
    }

    /**
     * Marks whether the stored asset requires rotation based on metadata.
     *
     * @param bool $needsRotation True if downstream consumers must rotate the asset.
     */
    public function setNeedsRotation(bool $needsRotation): void
    {
        $this->needsRotation = $needsRotation;
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
     * Returns the GeoHash with seven characters.
     */
    public function getGeohash7(): ?string
    {
        return $this->geohash7;
    }

    /**
     * Sets the GeoHash with seven characters.
     *
     * @param string|null $v GeoHash string with seven characters.
     */
    public function setGeohash7(?string $v): void
    {
        $this->geohash7 = $v;
    }

    /**
     * Returns the GeoHash with five characters.
     */
    public function getGeohash5(): ?string
    {
        return $this->geohash5;
    }

    /**
     * Sets the GeoHash with five characters.
     *
     * @param string|null $v GeoHash string with five characters.
     */
    public function setGeohash5(?string $v): void
    {
        $this->geohash5 = $v;
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
     * Returns the hash describing the home configuration.
     */
    public function getHomeConfigHash(): ?string
    {
        return $this->homeConfigHash;
    }

    /**
     * Stores the hash describing the home configuration.
     *
     * @param string|null $hash Hash computed from the home parameters.
     */
    public function setHomeConfigHash(?string $hash): void
    {
        $this->homeConfigHash = $hash;
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
     * Returns the burst index within the sequence.
     */
    public function getBurstIndex(): ?int
    {
        return $this->burstIndex;
    }

    /**
     * Sets the burst index within the sequence.
     *
     * @param int|null $v Zero-based or metadata-derived burst index.
     */
    public function setBurstIndex(?int $v): void
    {
        $this->burstIndex = $v;
    }

    /**
     * Returns whether the media is the representative member of its burst.
     */
    public function isBurstRepresentative(): ?bool
    {
        return $this->burstRepresentative;
    }

    /**
     * Marks the media as burst representative.
     */
    public function setBurstRepresentative(?bool $v): void
    {
        $this->burstRepresentative = $v;
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
     * Returns the associated live-pair media entity, if available.
     */
    public function getLivePairMedia(): ?self
    {
        return $this->livePairMedia;
    }

    /**
     * Links the media entity with its live photo counterpart.
     */
    public function setLivePairMedia(?self $media): void
    {
        $this->livePairMedia = $media;
    }

    /**
     * Indicates whether the media represents a RAW capture.
     */
    public function isRaw(): bool
    {
        return $this->isRaw;
    }

    /**
     * Sets whether the media represents a RAW capture.
     *
     * @param bool $v True when the media is a RAW image.
     */
    public function setIsRaw(bool $v): void
    {
        $this->isRaw = $v;
    }

    /**
     * Indicates whether the media uses the HEIC container format.
     */
    public function isHeic(): bool
    {
        return $this->isHeic;
    }

    /**
     * Sets whether the media uses the HEIC container format.
     *
     * @param bool $v True when the media is stored as HEIC.
     */
    public function setIsHeic(bool $v): void
    {
        $this->isHeic = $v;
    }

    /**
     * Indicates whether the media payload is encoded with HEVC.
     */
    public function isHevc(): bool
    {
        return $this->isHevc;
    }

    /**
     * Sets whether the media payload is encoded with HEVC.
     *
     * @param bool $v True when the payload is encoded with HEVC.
     */
    public function setIsHevc(bool $v): void
    {
        $this->isHevc = $v;
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
     * Returns the video rotation in degrees.
     */
    public function getVideoRotationDeg(): ?float
    {
        return $this->videoRotationDeg;
    }

    /**
     * Sets the video rotation in degrees.
     *
     * @param float|null $rotationDeg Rotation to apply clockwise.
     */
    public function setVideoRotationDeg(?float $rotationDeg): void
    {
        $this->videoRotationDeg = $rotationDeg;
    }

    /**
     * Indicates whether stabilisation metadata is present for the video stream.
     */
    public function getVideoHasStabilization(): ?bool
    {
        return $this->videoHasStabilization;
    }

    /**
     * Sets whether stabilisation metadata is present for the video stream.
     *
     * @param bool|null $hasStabilization True when stabilisation information exists.
     */
    public function setVideoHasStabilization(?bool $hasStabilization): void
    {
        $this->videoHasStabilization = $hasStabilization;
    }

    /**
     * Provides the normalised ffprobe stream metadata.
     *
     * @return array<int, array<int|string, int|float|string|bool|null|array<int|string, int|float|string|bool|null|array>>>|null
     */
    public function getVideoStreams(): ?array
    {
        return $this->videoStreams;
    }

    /**
     * Stores the normalised ffprobe stream metadata.
     *
     * @param array<int, array<int|string, int|float|string|bool|null|array<int|string, int|float|string|bool|null|array>>>|null $streams
     */
    public function setVideoStreams(?array $streams): void
    {
        $this->videoStreams = $streams;
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
     * Returns the assigned content kind.
     */
    public function getContentKind(): ?ContentKind
    {
        return $this->contentKind;
    }

    /**
     * Assigns the content kind.
     *
     * @param ContentKind|null $contentKind Categorised content kind.
     */
    public function setContentKind(?ContentKind $contentKind): void
    {
        $this->contentKind = $contentKind;
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
     * Returns the aggregated quality score.
     */
    public function getQualityScore(): ?float
    {
        return $this->qualityScore;
    }

    /**
     * Sets the aggregated quality score.
     *
     * @param float|null $score Quality score in the range [0,1].
     */
    public function setQualityScore(?float $score): void
    {
        $this->qualityScore = $score === null
            ? null
            : max(0.0, min(1.0, $score));
    }

    /**
     * Returns the aggregated exposure score.
     */
    public function getQualityExposure(): ?float
    {
        return $this->qualityExposure;
    }

    /**
     * Sets the aggregated exposure score.
     *
     * @param float|null $score Exposure score in the range [0,1].
     */
    public function setQualityExposure(?float $score): void
    {
        $this->qualityExposure = $score === null
            ? null
            : max(0.0, min(1.0, $score));
    }

    /**
     * Returns the aggregated noise score.
     */
    public function getQualityNoise(): ?float
    {
        return $this->qualityNoise;
    }

    /**
     * Sets the aggregated noise score.
     *
     * @param float|null $score Noise score in the range [0,1].
     */
    public function setQualityNoise(?float $score): void
    {
        $this->qualityNoise = $score === null
            ? null
            : max(0.0, min(1.0, $score));
    }

    /**
     * Returns the share of pixels affected by saturation clipping.
     */
    public function getQualityClipping(): ?float
    {
        return $this->qualityClipping;
    }

    /**
     * Sets the share of pixels affected by saturation clipping.
     *
     * @param float|null $share Share in the range [0,1].
     */
    public function setQualityClipping(?float $share): void
    {
        $this->qualityClipping = $share === null
            ? null
            : max(0.0, min(1.0, $share));
    }

    /**
     * Indicates whether the media falls below the configured quality thresholds.
     */
    public function isLowQuality(): bool
    {
        return $this->lowQuality;
    }

    /**
     * Marks the media as low quality.
     */
    public function setLowQuality(bool $lowQuality): void
    {
        $this->lowQuality = $lowQuality;
    }

    /**
     * Indicates whether the media should be hidden from downstream feeds.
     */
    public function isNoShow(): bool
    {
        return $this->noShow;
    }

    /**
     * Marks the media as hidden for downstream feeds.
     *
     * @param bool $noShow True to exclude the media from feeds.
     */
    public function setNoShow(bool $noShow): void
    {
        $this->noShow = $noShow;
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
        if ($v === null) {
            $this->phashPrefix = null;

            return;
        }

        $this->phashPrefix = strtolower(
            substr(
                $v,
                0,
                32
            )
        );
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

        $count = $v !== null ? count($v) : 0;

        $this->hasFaces = $count > 0;
        $this->facesCount = $count;
    }

    /**
     * Returns whether the media contains detected faces.
     */
    public function hasFaces(): bool
    {
        return $this->hasFaces;
    }

    /**
     * Sets whether the media contains detected faces.
     *
     * @param bool $value True when at least one face is detected.
     */
    public function setHasFaces(bool $value): void
    {
        $this->hasFaces = $value;
    }

    /**
     * Returns the number of detected faces.
     */
    public function getFacesCount(): int
    {
        return $this->facesCount;
    }

    /**
     * Stores the number of detected faces.
     *
     * @param int $count Total number of detected faces.
     */
    public function setFacesCount(int $count): void
    {
        $this->facesCount = $count;
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
     * Provides the list of scene tags with their confidence scores.
     *
     * @return list<array{label: string, score: float}>|null
     */
    public function getSceneTags(): ?array
    {
        return $this->sceneTags;
    }

    /**
     * Stores the computed scene tags.
     *
     * @param list<array{label: string, score: float}>|null $tags
     */
    public function setSceneTags(?array $tags): void
    {
        $this->sceneTags = $tags;
    }

    /**
     * Returns whether the media requires geocoding.
     */
    public function needsGeocode(): bool
    {
        return $this->needsGeocode;
    }

    /**
     * Marks the media as requiring or not requiring geocoding.
     *
     * @param bool $needsGeocode True if geocoding is still required.
     */
    public function setNeedsGeocode(bool $needsGeocode): void
    {
        $this->needsGeocode = $needsGeocode;
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
