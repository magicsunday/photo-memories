<?php
/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Entity;

use MagicSunday\Memories\Repository\WeatherObservationRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WeatherObservationRepository::class)]
#[ORM\Table(name: 'weather_observation')]
#[ORM\Index(name: 'idx_weather_bucket', fields: ['bucket'])]
final class WeatherObservation
{
    public const string DEFAULT_SOURCE = 'openweather';

    private const int COORD_PRECISION = 3;

    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $bucket;

    #[ORM\Column(type: Types::FLOAT)]
    private float $lat;

    #[ORM\Column(type: Types::FLOAT)]
    private float $lon;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $lookupHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $observedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $fetchedAt;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $hint;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $source;

    /**
     * @param array<string, mixed> $hint
     */
    public function __construct(
        int $bucket,
        float $lat,
        float $lon,
        DateTimeImmutable $observedAt,
        array $hint,
        string $source = self::DEFAULT_SOURCE
    ) {
        $this->bucket     = $bucket;
        $this->lat        = $lat;
        $this->lon        = $lon;
        $this->lookupHash = self::lookupHashFor($bucket, $lat, $lon);
        $this->observedAt = $observedAt;
        $this->fetchedAt  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->hint       = $hint;
        $this->source     = $source;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBucket(): int
    {
        return $this->bucket;
    }

    public function getLat(): float
    {
        return $this->lat;
    }

    public function getLon(): float
    {
        return $this->lon;
    }

    public function getLookupHash(): string
    {
        return $this->lookupHash;
    }

    public function getObservedAt(): DateTimeImmutable
    {
        return $this->observedAt;
    }

    public function getFetchedAt(): DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHint(): array
    {
        return $this->hint;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @param array<string, mixed> $hint
     */
    public function updateHint(DateTimeImmutable $observedAt, array $hint, string $source): void
    {
        $this->observedAt = $observedAt;
        $this->hint       = $hint;
        $this->source     = $source;
        $this->fetchedAt  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function quantizeCoordinate(float $value): float
    {
        return \round($value, self::COORD_PRECISION);
    }

    public static function bucketFromTimestamp(int $timestamp): int
    {
        return \intdiv($timestamp, 3600);
    }

    public static function observationTimeFromTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function lookupHashFor(int $bucket, float $lat, float $lon): string
    {
        return \sha1(\sprintf('%d|%.3f|%.3f', $bucket, $lat, $lon));
    }

    public static function lookupHashFromRaw(float $lat, float $lon, int $timestamp): string
    {
        $bucket = self::bucketFromTimestamp($timestamp);
        $qLat   = self::quantizeCoordinate($lat);
        $qLon   = self::quantizeCoordinate($lon);

        return self::lookupHashFor($bucket, $qLat, $qLon);
    }

    /**
     * @param array<string, mixed> $hint
     */
    public static function createFromHint(float $lat, float $lon, int $timestamp, array $hint, string $source): self
    {
        $bucket     = self::bucketFromTimestamp($timestamp);
        $quantLat   = self::quantizeCoordinate($lat);
        $quantLon   = self::quantizeCoordinate($lon);
        $observedAt = self::observationTimeFromTimestamp($timestamp);

        return new self($bucket, $quantLat, $quantLon, $observedAt, $hint, $source);
    }
}
