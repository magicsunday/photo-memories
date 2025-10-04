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

            public function link(Media $media, string $acceptLanguage = 'de', bool $forceRefreshPois = false): ?Location
            {
                Assert::assertSame('de', $acceptLanguage);
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

        $summary = $processor->process([$mediaA, $mediaB], false, false, new NullOutput());

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

            public function link(Media $media, string $acceptLanguage = 'de', bool $forceRefreshPois = false): ?Location
            {
                Assert::assertSame($this->expectedMedia, $media);
                Assert::assertSame('de', $acceptLanguage);
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

        $summary = $processor->process([$media], true, true, new NullOutput());

        self::assertSame([$location], $summary->getLocationsForPoiUpdate());
        self::assertFalse($media->needsGeocode());
    }
}
