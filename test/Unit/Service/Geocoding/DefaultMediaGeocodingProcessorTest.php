<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Geocoding\DefaultMediaGeocodingProcessor;
use MagicSunday\Memories\Service\Geocoding\MediaLocationLinkerInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class DefaultMediaGeocodingProcessorTest extends TestCase
{
    #[Test]
    public function processesMediaAndCollectsUniqueLocations(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $mediaA = new Media('a.jpg', 'hash-a', 100);
        $mediaB = new Media('b.jpg', 'hash-b', 200);

        $mediaA->setNeedsGeocode(true);
        $mediaB->setNeedsGeocode(true);

        $location = new Location('nominatim', '1', 'Berlin', 1.0, 2.0, 'cell');

        $linker = new class($location, $mediaA, $mediaB) implements MediaLocationLinkerInterface {
            private int $invocations = 0;

            /** @var list<int> */
            private array $networkCalls = [1, 0];

            public function __construct(
                private readonly Location $location,
                private readonly Media $first,
                private readonly Media $second,
            ) {
            }

            public function link(
                Media $media,
                string $acceptLanguage = 'de',
                bool $forceRefreshLocations = false,
                bool $forceRefreshPois = false,
            ): ?Location
            {
                Assert::assertSame('de', $acceptLanguage);
                Assert::assertFalse($forceRefreshLocations);
                Assert::assertFalse($forceRefreshPois);

                if ($this->invocations === 0) {
                    Assert::assertSame($this->first, $media);
                    Assert::assertTrue($media->needsGeocode());
                    $media->setLocation($this->location);
                    $media->setNeedsGeocode(false);
                    ++$this->invocations;

                    return $this->location;
                }

                Assert::assertSame($this->second, $media);
                Assert::assertTrue($media->needsGeocode());
                ++$this->invocations;

                return null;
            }

            public function consumeLastNetworkCalls(): int
            {
                return $this->networkCalls[$this->invocations - 1] ?? 0;
            }
        };

        $processor = new DefaultMediaGeocodingProcessor($entityManager, $linker, 'de', 0, 10);

        $summary = $processor->process([$mediaA, $mediaB], false, false, false, new NullOutput());

        self::assertSame(2, $summary->getProcessed());
        self::assertSame(1, $summary->getLinked());
        self::assertSame(1, $summary->getNetworkCalls());
        self::assertSame([$location], $summary->getLocationsForPoiUpdate());
        self::assertFalse($mediaA->needsGeocode());
        self::assertTrue($mediaB->needsGeocode());
    }

    #[Test]
    public function keepsLocationsForRefreshAndSkipsFlushDuringDryRun(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $media = new Media('c.jpg', 'hash-c', 300);
        $media->setNeedsGeocode(true);
        $location = (new Location('nominatim', '2', 'Hamburg', 1.0, 2.0, 'cell'))->setPois([['name' => 'Alster']]);

        $linker = new class($media, $location) implements MediaLocationLinkerInterface {
            private bool $linked = false;

            public function __construct(
                private readonly Media $expectedMedia,
                private readonly Location $location,
            ) {
            }

            public function link(
                Media $media,
                string $acceptLanguage = 'de',
                bool $forceRefreshLocations = false,
                bool $forceRefreshPois = false,
            ): ?Location
            {
                Assert::assertSame($this->expectedMedia, $media);
                Assert::assertSame('de', $acceptLanguage);
                Assert::assertTrue($forceRefreshLocations);
                Assert::assertTrue($forceRefreshPois);

                $this->linked = true;

                $media->setLocation($this->location);
                $media->setNeedsGeocode(false);

                return $this->location;
            }

            public function consumeLastNetworkCalls(): int
            {
                Assert::assertTrue($this->linked);

                return 0;
            }
        };

        $processor = new DefaultMediaGeocodingProcessor($entityManager, $linker, 'de', 0, 10);

        $summary = $processor->process([$media], true, true, true, new NullOutput());

        self::assertSame([$location], $summary->getLocationsForPoiUpdate());
        self::assertFalse($media->needsGeocode());
    }

    #[Test]
    public function forcesReverseGeocodingWhenRefreshingLocations(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $mediaA = new Media('force-a.jpg', 'hash-force-a', 400);
        $mediaB = new Media('force-b.jpg', 'hash-force-b', 500);

        $mediaA->setGpsLat(52.0);
        $mediaA->setGpsLon(13.0);
        $mediaB->setGpsLat(52.0);
        $mediaB->setGpsLon(13.0);

        $mediaA->setNeedsGeocode(true);
        $mediaB->setNeedsGeocode(true);

        $linker = new class() implements MediaLocationLinkerInterface {
            private ?Location $cached = null;

            private int $lastNetworkCalls = 0;

            /** @var list<bool> */
            public array $forcedFlags = [];

            public function link(
                Media $media,
                string $acceptLanguage = 'de',
                bool $forceRefreshLocations = false,
                bool $forceRefreshPois = false,
            ): ?Location {
                $this->forcedFlags[] = $forceRefreshLocations;

                if ($forceRefreshLocations) {
                    $this->cached = null;
                }

                if ($this->cached instanceof Location) {
                    $this->lastNetworkCalls = 0;
                    $media->setLocation($this->cached);
                    $media->setNeedsGeocode(false);

                    return $this->cached;
                }

                $this->lastNetworkCalls = 1;
                $location             = new Location(
                    'nominatim',
                    'forced-' . spl_object_id($media),
                    'Berlin',
                    $media->getGpsLat() ?? 0.0,
                    $media->getGpsLon() ?? 0.0,
                    'cell-force',
                );
                $this->cached = $location;

                $media->setLocation($location);
                $media->setNeedsGeocode(false);

                return $location;
            }

            public function consumeLastNetworkCalls(): int
            {
                $value                 = $this->lastNetworkCalls;
                $this->lastNetworkCalls = 0;

                return $value;
            }
        };

        $processor = new DefaultMediaGeocodingProcessor($entityManager, $linker, 'de', 0, 10);

        $processor->process([$mediaA, $mediaB], false, false, false, new NullOutput());

        $mediaA->setNeedsGeocode(true);
        $mediaB->setNeedsGeocode(true);

        $summary = $processor->process([$mediaA, $mediaB], false, true, false, new NullOutput());

        self::assertSame([false, false, true, true], $linker->forcedFlags);
        self::assertSame(2, $summary->getNetworkCalls());
        self::assertSame(2, $summary->getLinked());
    }
}
