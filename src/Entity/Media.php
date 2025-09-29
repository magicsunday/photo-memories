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

#[ORM\Entity]
#[ORM\Table(name: 'media')]
#[ORM\Index(name: 'idx_taken_at', fields: ['takenAt'])]
#[ORM\Index(name: 'idx_checksum', fields: ['checksum'])]
class Media
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: Types::STRING, length: 2048, unique: true)]
    private string $path;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $checksum;

    #[ORM\Column(type: Types::BIGINT)]
    private int $size;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $mime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $takenAt = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $timezoneOffsetMin = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsLat = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsLon = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $cameraModel = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $cameraMake = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $lensModel = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $focalLengthMm = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $focalLength35mm = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $apertureF = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $exposureTimeS = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $iso = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $flashFired = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $orientation = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsAlt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsAccuracyM = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsHeadingDeg = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $gpsSpeedMps = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $geoCell8 = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $distanceKmFromHome = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $burstUuid = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $subSecOriginal = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $livePairChecksum = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isVideo = false;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $videoDurationS = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $videoFps = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $videoCodec = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isSlowMo = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isPortrait = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isPanorama = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $sharpness = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $brightness = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $contrast = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $entropy = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $colorfulness = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $phashPrefix = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $phash = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $dhash = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $ahash = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keywords = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $persons = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $thumbnails = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    public function __construct(string $path, string $checksum, int $size)
    {
        $this->path      = $path;
        $this->checksum  = $checksum;
        $this->size      = $size;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMime(): ?string
    {
        return $this->mime;
    }

    public function setMime(?string $mime): void
    {
        $this->mime = $mime;
    }

    public function getTakenAt(): ?DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function setTakenAt(?DateTimeImmutable $takenAt): void
    {
        $this->takenAt = $takenAt;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): void
    {
        $this->width = $width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): void
    {
        $this->height = $height;
    }

    public function getGpsLat(): ?float
    {
        return $this->gpsLat;
    }

    public function setGpsLat(?float $gpsLat): void
    {
        $this->gpsLat = $gpsLat;
    }

    public function getGpsLon(): ?float
    {
        return $this->gpsLon;
    }

    public function setGpsLon(?float $gpsLon): void
    {
        $this->gpsLon = $gpsLon;
    }

    public function getCameraModel(): ?string
    {
        return $this->cameraModel;
    }

    public function setCameraModel(?string $cameraModel): void
    {
        $this->cameraModel = $cameraModel;
    }

    public function getPhash(): ?string
    {
        return $this->phash;
    }

    public function setPhash(?string $phash): void
    {
        $this->phash = $phash;
    }

    public function getThumbnails(): ?array
    {
        return $this->thumbnails;
    }

    public function setThumbnails(?array $thumbnails): void
    {
        $this->thumbnails = $thumbnails;
    }

    public function getTimezoneOffsetMin(): ?int
    {
        return $this->timezoneOffsetMin;
    }

    public function setTimezoneOffsetMin(?int $v): void
    {
        $this->timezoneOffsetMin = $v;
    }

    public function getOrientation(): ?int
    {
        return $this->orientation;
    }

    public function setOrientation(?int $v): void
    {
        $this->orientation = $v;
    }

    public function getCameraMake(): ?string
    {
        return $this->cameraMake;
    }

    public function setCameraMake(?string $v): void
    {
        $this->cameraMake = $v;
    }

    public function getLensModel(): ?string
    {
        return $this->lensModel;
    }

    public function setLensModel(?string $v): void
    {
        $this->lensModel = $v;
    }

    public function getFocalLengthMm(): ?float
    {
        return $this->focalLengthMm;
    }

    public function setFocalLengthMm(?float $v): void
    {
        $this->focalLengthMm = $v;
    }

    public function getFocalLength35mm(): ?int
    {
        return $this->focalLength35mm;
    }

    public function setFocalLength35mm(?int $v): void
    {
        $this->focalLength35mm = $v;
    }

    public function getApertureF(): ?float
    {
        return $this->apertureF;
    }

    public function setApertureF(?float $v): void
    {
        $this->apertureF = $v;
    }

    public function getExposureTimeS(): ?float
    {
        return $this->exposureTimeS;
    }

    public function setExposureTimeS(?float $v): void
    {
        $this->exposureTimeS = $v;
    }

    public function getIso(): ?int
    {
        return $this->iso;
    }

    public function setIso(?int $v): void
    {
        $this->iso = $v;
    }

    public function isFlashFired(): ?bool
    {
        return $this->flashFired;
    }

    public function setFlashFired(?bool $v): void
    {
        $this->flashFired = $v;
    }

    public function getGpsAlt(): ?float
    {
        return $this->gpsAlt;
    }

    public function setGpsAlt(?float $v): void
    {
        $this->gpsAlt = $v;
    }

    public function getGpsAccuracyM(): ?float
    {
        return $this->gpsAccuracyM;
    }

    public function setGpsAccuracyM(?float $v): void
    {
        $this->gpsAccuracyM = $v;
    }

    public function getGpsHeadingDeg(): ?float
    {
        return $this->gpsHeadingDeg;
    }

    public function setGpsHeadingDeg(?float $v): void
    {
        $this->gpsHeadingDeg = $v;
    }

    public function getGpsSpeedMps(): ?float
    {
        return $this->gpsSpeedMps;
    }

    public function setGpsSpeedMps(?float $v): void
    {
        $this->gpsSpeedMps = $v;
    }

    public function getGeoCell8(): ?string
    {
        return $this->geoCell8;
    }

    public function setGeoCell8(?string $v): void
    {
        $this->geoCell8 = $v;
    }

    public function getDistanceKmFromHome(): ?float
    {
        return $this->distanceKmFromHome;
    }

    public function setDistanceKmFromHome(?float $v): void
    {
        $this->distanceKmFromHome = $v;
    }

    public function getBurstUuid(): ?string
    {
        return $this->burstUuid;
    }

    public function setBurstUuid(?string $v): void
    {
        $this->burstUuid = $v;
    }

    public function getSubSecOriginal(): ?int
    {
        return $this->subSecOriginal;
    }

    public function setSubSecOriginal(?int $v): void
    {
        $this->subSecOriginal = $v;
    }

    public function getLivePairChecksum(): ?string
    {
        return $this->livePairChecksum;
    }

    public function setLivePairChecksum(?string $v): void
    {
        $this->livePairChecksum = $v;
    }

    public function isVideo(): bool
    {
        return $this->isVideo;
    }

    public function setIsVideo(bool $v): void
    {
        $this->isVideo = $v;
    }

    public function getVideoDurationS(): ?float
    {
        return $this->videoDurationS;
    }

    public function setVideoDurationS(?float $v): void
    {
        $this->videoDurationS = $v;
    }

    public function getVideoFps(): ?float
    {
        return $this->videoFps;
    }

    public function setVideoFps(?float $v): void
    {
        $this->videoFps = $v;
    }

    public function getVideoCodec(): ?string
    {
        return $this->videoCodec;
    }

    public function setVideoCodec(?string $v): void
    {
        $this->videoCodec = $v;
    }

    public function isSlowMo(): ?bool
    {
        return $this->isSlowMo;
    }

    public function setIsSlowMo(?bool $v): void
    {
        $this->isSlowMo = $v;
    }

    public function isPortrait(): ?bool
    {
        return $this->isPortrait;
    }

    public function setIsPortrait(?bool $v): void
    {
        $this->isPortrait = $v;
    }

    public function isPanorama(): ?bool
    {
        return $this->isPanorama;
    }

    public function setIsPanorama(?bool $v): void
    {
        $this->isPanorama = $v;
    }

    public function getSharpness(): ?float
    {
        return $this->sharpness;
    }

    public function setSharpness(?float $v): void
    {
        $this->sharpness = $v;
    }

    public function getBrightness(): ?float
    {
        return $this->brightness;
    }

    public function setBrightness(?float $v): void
    {
        $this->brightness = $v;
    }

    public function getContrast(): ?float
    {
        return $this->contrast;
    }

    public function setContrast(?float $v): void
    {
        $this->contrast = $v;
    }

    public function getEntropy(): ?float
    {
        return $this->entropy;
    }

    public function setEntropy(?float $v): void
    {
        $this->entropy = $v;
    }

    public function getColorfulness(): ?float
    {
        return $this->colorfulness;
    }

    public function setColorfulness(?float $v): void
    {
        $this->colorfulness = $v;
    }

    public function getPhashPrefix(): ?string
    {
        return $this->phashPrefix;
    }

    public function setPhashPrefix(?string $v): void
    {
        $this->phashPrefix = $v;
    }

    public function getDhash(): ?string
    {
        return $this->dhash;
    }

    public function setDhash(?string $v): void
    {
        $this->dhash = $v;
    }

    public function getAhash(): ?string
    {
        return $this->ahash;
    }

    public function setAhash(?string $v): void
    {
        $this->ahash = $v;
    }

    public function getKeywords(): ?array
    {
        return $this->keywords;
    }

    public function setKeywords(?array $v): void
    {
        $this->keywords = $v;
    }

    public function getPersons(): ?array
    {
        return $this->persons;
    }

    public function setPersons(?array $v): void
    {
        $this->persons = $v;
    }

    public function getFeatures(): ?array
    {
        return $this->features;
    }

    public function setFeatures(?array $v): void
    {
        $this->features = $v;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): void
    {
        $this->location = $location;
    }
}
