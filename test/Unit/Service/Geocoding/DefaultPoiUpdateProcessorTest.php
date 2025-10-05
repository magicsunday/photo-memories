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
use MagicSunday\Memories\Service\Geocoding\DefaultPoiUpdateProcessor;
use MagicSunday\Memories\Service\Geocoding\PoiEnsurerInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class DefaultPoiUpdateProcessorTest extends TestCase
{
    #[Test]
    public function processesLocationsAndCountsUpdates(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $locationA = new Location('nominatim', '1', 'Berlin', 1.0, 2.0, 'cell-a');
        $locationB = new Location('nominatim', '2', 'Hamburg', 3.0, 4.0, 'cell-b');

        $resolver = new class($locationA, $locationB) implements PoiEnsurerInterface {
            private int $calls = 0;

            /** @var list<bool> $network */
            private array $network = [true, false];

            public function __construct(
                private readonly Location $first,
                private readonly Location $second,
            ) {
            }

            public function ensurePois(Location $location, bool $refreshPois = false): void
            {
                Assert::assertFalse($refreshPois);

                if ($this->calls === 0) {
                    Assert::assertSame($this->first, $location);
                    $location->setPois([
                        ['name' => 'Brandenburger Tor'],
                    ]);
                } else {
                    Assert::assertSame($this->second, $location);
                }

                ++$this->calls;
            }

            public function consumeLastUsedNetwork(): bool
            {
                return $this->network[$this->calls - 1] ?? false;
            }
        };

        $processor = new DefaultPoiUpdateProcessor($entityManager, $resolver, 10);

        $summary = $processor->process([$locationA, $locationB], false, false, new NullOutput());

        self::assertSame(2, $summary->getProcessed());
        self::assertSame(1, $summary->getUpdated());
        self::assertSame(1, $summary->getNetworkCalls());
    }

    #[Test]
    public function skipsPersistenceDuringDryRun(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $resolver = new class implements PoiEnsurerInterface {
            private bool $ensured = false;

            public function ensurePois(Location $location, bool $refreshPois = false): void
            {
                Assert::assertTrue($refreshPois);
                $this->ensured = true;
            }

            public function consumeLastUsedNetwork(): bool
            {
                Assert::assertTrue($this->ensured);

                return false;
            }
        };

        $location = new Location('nominatim', '3', 'München', 5.0, 6.0, 'cell-c');

        $processor = new DefaultPoiUpdateProcessor($entityManager, $resolver, 10);

        $summary = $processor->process([$location], true, true, new NullOutput());

        self::assertSame(1, $summary->getProcessed());
        self::assertSame(0, $summary->getUpdated());
        self::assertSame(0, $summary->getNetworkCalls());
    }
}
