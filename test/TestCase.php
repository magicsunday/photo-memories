<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;

abstract class TestCase extends BaseTestCase
{
    private ?string $fixtureDir = null;

    protected function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }

    protected function makeMedia(
        int $id,
        string $path,
        DateTimeInterface|string|null $takenAt = null,
        ?float $lat = null,
        ?float $lon = null,
        ?Location $location = null,
        ?callable $configure = null,
        int $size = 1024,
        ?string $checksum = null,
    ): Media {
        $media = new Media(
            path: $path,
            checksum: $checksum ?? str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: $size,
        );

        $this->assignId($media, $id);

        if ($takenAt !== null) {
            $media->setTakenAt($this->normaliseDateTime($takenAt));
        }

        if ($lat !== null) {
            $media->setGpsLat($lat);
        }

        if ($lon !== null) {
            $media->setGpsLon($lon);
        }

        if ($location !== null) {
            $media->setLocation($location);

            if ($lat === null) {
                $media->setGpsLat($location->getLat());
            }

            if ($lon === null) {
                $media->setGpsLon($location->getLon());
            }
        }

        if ($configure !== null) {
            $configure($media);
        }

        return $media;
    }

    protected function makeMediaFixture(
        int $id,
        string $filename,
        DateTimeInterface|string|null $takenAt = null,
        ?float $lat = null,
        ?float $lon = null,
        ?Location $location = null,
        ?callable $configure = null,
        int $size = 1024,
        ?string $checksum = null,
    ): Media {
        return $this->makeMedia(
            id: $id,
            path: $this->fixturePath($filename),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: $configure,
            size: $size,
            checksum: $checksum,
        );
    }

    protected function fixturePath(string $filename): string
    {
        return $this->fixtureDir() . '/' . ltrim($filename, '/');
    }

    private function fixtureDir(): string
    {
        if ($this->fixtureDir === null) {
            $reflection = new ReflectionClass($this);
            $this->fixtureDir = dirname((string) $reflection->getFileName()) . '/fixtures';
        }

        return $this->fixtureDir;
    }

    private function normaliseDateTime(DateTimeInterface|string $value): DateTimeImmutable
    {
        if (is_string($value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        return DateTimeImmutable::createFromInterface($value);
    }
}
