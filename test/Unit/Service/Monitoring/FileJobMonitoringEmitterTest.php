<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Psr\Clock;

use DateTimeImmutable;

if (!interface_exists(ClockInterface::class)) {
    interface ClockInterface
    {
        public function now(): DateTimeImmutable;
    }
}

namespace MagicSunday\Memories\Test\Unit\Service\Monitoring;

use DateTimeImmutable;
use JsonException;
use MagicSunday\Memories\Service\Monitoring\FileJobMonitoringEmitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

use function array_filter;
use function explode;
use function file_exists;
use function file_get_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const JSON_THROW_ON_ERROR;

final class FileJobMonitoringEmitterTest extends TestCase
{
    #[Test]
    public function writesJsonLine(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'monitoring-test-');
        if (!is_string($path)) {
            self::markTestSkipped('Temp file could not be created.');
        }

        if (file_exists($path)) {
            unlink($path);
        }

        $clock = new class() implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2024-01-01T12:00:00+00:00');
            }
        };

        $emitter = new FileJobMonitoringEmitter($path, true, $clock);
        $emitter->emit('geocoding.poi_update', 'started', ['foo' => 'bar']);

        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $lines = array_filter(explode("\n", trim($contents)));
        self::assertCount(1, $lines);

        try {
            $payload = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail($exception->getMessage());
        }

        self::assertSame('geocoding.poi_update', $payload['job']);
        self::assertSame('started', $payload['status']);
        self::assertSame('bar', $payload['foo']);
        self::assertSame('1.0', $payload['schema_version']);
        self::assertSame('2024-01-01T12:00:00+00:00', $payload['timestamp']);

        unlink($path);
    }

    #[Test]
    public function mergesPhaseMetricsIntoPayload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'monitoring-test-');
        if (!is_string($path)) {
            self::markTestSkipped('Temp file could not be created.');
        }

        if (file_exists($path)) {
            unlink($path);
        }

        $emitter = new FileJobMonitoringEmitter($path, true, null, '2.0');
        $emitter->emit('member.selection', 'completed', [
            'phase_metrics' => [
                'counts'       => ['filtering' => ['members' => ['input' => 5]]],
                'medians'      => ['selecting' => ['spacing_seconds' => 2.5]],
                'percentiles'  => ['selecting' => ['spacing_seconds' => ['p90' => 3.0]]],
                'durations_ms' => ['filtering' => 12.4],
            ],
        ]);

        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $lines = array_filter(explode("\n", trim($contents)));
        self::assertCount(1, $lines);

        try {
            $payload = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail($exception->getMessage());
        }

        self::assertSame('2.0', $payload['schema_version']);
        self::assertArrayHasKey('phase_counts', $payload);
        self::assertArrayHasKey('phase_medians', $payload);
        self::assertArrayHasKey('phase_percentiles', $payload);
        self::assertArrayHasKey('phase_durations_ms', $payload);

        unlink($path);
    }
}
