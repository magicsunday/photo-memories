<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test;

use Closure;
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
        Closure::bind(function (Media $m, int $value): void {
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
        ?callable $factory = null,
    ): Media {
        $mediaChecksum = $checksum ?? str_pad((string) $id, 64, '0', STR_PAD_LEFT);

        $media = $factory !== null
            ? $factory($path, $mediaChecksum, $size)
            : new Media(
                path: $path,
                checksum: $mediaChecksum,
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

        if ($location instanceof Location) {
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
        ?callable $factory = null,
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
            factory: $factory,
        );
    }

    /**
     * @param list<int> $personIds
     */
    protected function makePersonTaggedMedia(
        int $id,
        string $path,
        array $personIds,
        DateTimeInterface|string|null $takenAt = null,
        ?float $lat = null,
        ?float $lon = null,
        ?Location $location = null,
        ?callable $configure = null,
        int $size = 1024,
        ?string $checksum = null,
    ): Media {
        $factory = (static fn (string $mediaPath, string $mediaChecksum, int $mediaSize): Media => new class($mediaPath, $mediaChecksum, $mediaSize, $personIds) extends Media {
            /**
             * @var list<int>
             */
            private readonly array $personIds;

            /**
             * @param list<int> $personIds
             */
            public function __construct(string $path, string $checksum, int $size, array $personIds)
            {
                parent::__construct($path, $checksum, $size);
                $this->personIds = $personIds;
            }

            /**
             * @return list<int>
             */
            public function getPersonIds(): array
            {
                return $this->personIds;
            }
        });

        return $this->makeMedia(
            id: $id,
            path: $path,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: $configure,
            size: $size,
            checksum: $checksum,
            factory: $factory,
        );
    }

    /**
     * @param list<int> $personIds
     */
    protected function makePersonTaggedMediaFixture(
        int $id,
        string $filename,
        array $personIds,
        DateTimeInterface|string|null $takenAt = null,
        ?float $lat = null,
        ?float $lon = null,
        ?Location $location = null,
        ?callable $configure = null,
        int $size = 1024,
        ?string $checksum = null,
    ): Media {
        return $this->makePersonTaggedMedia(
            id: $id,
            path: $this->fixturePath($filename),
            personIds: $personIds,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: $configure,
            size: $size,
            checksum: $checksum,
        );
    }

    protected function makeLocation(
        string $providerPlaceId,
        string $displayName,
        float $lat,
        float $lon,
        string $provider = 'osm',
        ?string $cell = null,
        ?string $city = null,
        ?string $country = null,
        ?string $suburb = null,
        ?callable $configure = null,
    ): Location {
        $location = new Location(
            provider: $provider,
            providerPlaceId: $providerPlaceId,
            displayName: $displayName,
            lat: $lat,
            lon: $lon,
            cell: $cell ?? 'cell-' . $providerPlaceId,
        );

        if ($city !== null) {
            $location->setCity($city);
        }

        if ($country !== null) {
            $location->setCountry($country);
        }

        if ($suburb !== null) {
            $location->setSuburb($suburb);
        }

        if ($configure !== null) {
            $configure($location);
        }

        return $location;
    }

    /**
     * Executes the callback while ensuring the formatted portion of the current
     * time does not change during the test. The callback receives the captured
     * anchor time and a stability checker. Returning <code>true</code> marks the
     * attempt as successful; returning <code>false</code> retries with a new
     * anchor. When all attempts observe a changing clock the test is skipped to
     * avoid flaky behaviour around day/month rollovers.
     *
     * @param callable(DateTimeImmutable, callable():bool):bool $callback
     */
    protected function runWithStableClock(
        DateTimeZone $timezone,
        string $format,
        callable $callback,
        int $maxAttempts = 3,
        string $skipMessage = 'System clock changed during test execution.',
    ): void {
        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $anchor   = new DateTimeImmutable('now', $timezone);
            $isStable = (static fn (): bool => (new DateTimeImmutable('now', $timezone))->format($format) === $anchor->format($format));

            if ($callback($anchor, $isStable) === true) {
                return;
            }
        }

        self::markTestSkipped($skipMessage);
    }

    protected function fixturePath(string $filename): string
    {
        return $this->fixtureDir() . '/' . ltrim($filename, '/');
    }

    private function fixtureDir(): string
    {
        if ($this->fixtureDir === null) {
            $reflection       = new ReflectionClass($this);
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
