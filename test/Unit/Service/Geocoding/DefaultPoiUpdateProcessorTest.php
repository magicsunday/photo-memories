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
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
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

            /** @var list<bool> */
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

    #[Test]
    public function emitsMonitoringEvents(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $location = new Location('nominatim', '4', 'Köln', 7.1, 8.2, 'cell-d');

        $resolver = new class implements PoiEnsurerInterface {
            public function ensurePois(Location $location, bool $refreshPois = false): void
            {
                $location->setPois([
                    ['name' => 'Kölner Dom'],
                ]);
            }

            public function consumeLastUsedNetwork(): bool
            {
                return true;
            }
        };

        $emitter = new class implements JobMonitoringEmitterInterface {
            /** @var list<array{job:string,status:string,context:array<string,mixed>}> */
            public array $events = [];

            public function emit(string $job, string $status, array $context = []): void
            {
                $this->events[] = [
                    'job'     => $job,
                    'status'  => $status,
                    'context' => $context,
                ];
            }
        };

        $processor = new DefaultPoiUpdateProcessor($entityManager, $resolver, 10, $emitter);

        $summary = $processor->process([$location], false, false, new NullOutput());

        self::assertCount(2, $emitter->events);
        self::assertSame('geocoding.poi_update', $emitter->events[0]['job']);
        self::assertSame('started', $emitter->events[0]['status']);
        self::assertSame(1, $emitter->events[0]['context']['total']);
        self::assertFalse($emitter->events[0]['context']['dryRun']);

        self::assertSame('finished', $emitter->events[1]['status']);
        self::assertSame($summary->getProcessed(), $emitter->events[1]['context']['processed']);
        self::assertSame($summary->getUpdated(), $emitter->events[1]['context']['updated']);
        self::assertSame($summary->getNetworkCalls(), $emitter->events[1]['context']['networkCalls']);
    }
}
