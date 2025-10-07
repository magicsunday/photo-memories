<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Service\Metadata\Support\FilenameDateParser;
use MagicSunday\Memories\Service\Metadata\TimeNormalizer;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function file_exists;
use function file_put_contents;
use function rename;
use function sprintf;
use function strtotime;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function unlink;

final class TimeNormalizerTest extends TestCase
{
    #[Test]
    public function keepsExifTimestampAndAppendsSummary(): void
    {
        $normalizer = $this->createNormalizer();

        $media = $this->makeMedia(
            id: 1,
            path: '/library/IMG_20240101_101112.jpg',
            takenAt: '2024-01-01T09:11:12+00:00',
            configure: static function (Media $item): void {
                $item->setIndexLog('initial');
                $item->setTzConfidence(1.0);
            },
        );

        $result = $normalizer->extract($media->getPath(), $media);

        self::assertSame(TimeSource::EXIF, $result->getTimeSource());
        self::assertSame(1.0, $result->getTzConfidence());
        $expectedSummary = sprintf(
            'time=%s; tz=%s; off=%+d',
            TimeSource::EXIF->value,
            (string) $result->getTzId(),
            (int) $result->getTimezoneOffsetMin(),
        );
        self::assertSame('initial' . "\n" . $expectedSummary, $result->getIndexLog());
    }

    #[Test]
    public function doesNotOverrideQuickTimeTimestamp(): void
    {
        $normalizer = $this->createNormalizer();

        $media = $this->makeMedia(
            id: 2,
            path: '/library/PXL_20221105_141516.mp4',
            takenAt: new DateTimeImmutable('2022-11-05T14:15:16+01:00'),
            configure: static function (Media $item): void {
                $item->setTimeSource(TimeSource::VIDEO_QUICKTIME);
                $item->setIndexLog('video');
            },
        );

        $result = $normalizer->extract($media->getPath(), $media);

        self::assertSame(TimeSource::VIDEO_QUICKTIME, $result->getTimeSource());
        $expectedSummary = sprintf(
            'time=%s; tz=%s; off=%+d',
            TimeSource::VIDEO_QUICKTIME->value,
            (string) $result->getTzId(),
            (int) $result->getTimezoneOffsetMin(),
        );
        self::assertSame('video' . "\n" . $expectedSummary, $result->getIndexLog());
    }

    #[Test]
    public function parsesTimestampFromFilename(): void
    {
        $normalizer = $this->createNormalizer(defaultTimezone: 'Europe/Berlin');

        $media = $this->makeMedia(
            id: 3,
            path: '/library/20230721_141516.jpg',
        );

        $result = $normalizer->extract($media->getPath(), $media);

        self::assertSame(TimeSource::FILENAME, $result->getTimeSource());
        self::assertSame('Europe/Berlin', $result->getTzId());
        self::assertSame(120, $result->getTimezoneOffsetMin());
        self::assertSame(0.4, $result->getTzConfidence());
        self::assertSame('time=FILENAME; tz=Europe/Berlin; off=+120', $result->getIndexLog());
    }

    #[Test]
    public function fallsBackToFileModificationTime(): void
    {
        $normalizer = $this->createNormalizer(defaultTimezone: 'Europe/Berlin');

        $tmp = tempnam(sys_get_temp_dir(), 'pm_time_');
        self::assertIsString($tmp);

        $filename = sprintf('%s/no-pattern-file.jpg', sys_get_temp_dir());
        if (file_exists($filename)) {
            unlink($filename);
        }
        self::assertTrue(rename($tmp, $filename));

        $timestamp = strtotime('2020-02-03T04:05:06Z');
        self::assertNotFalse($timestamp);
        self::assertTrue(touch($filename, $timestamp));

        $media = $this->makeMedia(
            id: 4,
            path: $filename,
        );

        $result = $normalizer->extract($media->getPath(), $media);

        self::assertSame(TimeSource::FILE_MTIME, $result->getTimeSource());
        self::assertSame('Europe/Berlin', $result->getTzId());
        self::assertSame(60, $result->getTimezoneOffsetMin());
        self::assertSame(0.2, $result->getTzConfidence());
        $expectedSummary = sprintf(
            'time=%s; tz=%s; off=%+d',
            TimeSource::FILE_MTIME->value,
            (string) $result->getTzId(),
            (int) $result->getTimezoneOffsetMin(),
        );
        self::assertSame($expectedSummary, $result->getIndexLog());

        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    #[Test]
    public function resolvesTimezoneFromResolver(): void
    {
        $rome       = new DateTimeZone('Europe/Rome');
        $normalizer = $this->createNormalizer(resolvedTimezone: $rome, defaultTimezone: 'UTC');

        $media = $this->makeMedia(
            id: 5,
            path: '/library/IMG_20230812_101112.jpg',
            takenAt: '2024-08-12T10:11:12+00:00',
            location: new Location('nominatim', 'rome-1', 'Roma', 41.902782, 12.496366, 'u6h1'),
        );
        $media->setTimeSource(TimeSource::FILENAME);
        $media->setTzId(null);
        $media->setCapturedLocal(null);
        $media->setGpsLat(41.902782);
        $media->setGpsLon(12.496366);

        $result = $normalizer->extract($media->getPath(), $media);

        self::assertSame(TimeSource::FILENAME, $result->getTimeSource());
        self::assertSame('Europe/Rome', $result->getTzId());
        self::assertSame(120, $result->getTimezoneOffsetMin());
        self::assertSame(0.8, $result->getTzConfidence());
        self::assertStringContainsString('tz=Europe/Rome', (string) $result->getIndexLog());
    }

    #[Test]
    public function respectsConfiguredFallbackPriority(): void
    {
        $normalizer = $this->createNormalizer(
            defaultTimezone: 'Europe/Berlin',
            priority: ['file_mtime', 'filename'],
        );

        $filename = sprintf('%s/20230721_141516.jpg', sys_get_temp_dir());
        if (file_exists($filename)) {
            unlink($filename);
        }

        self::assertNotFalse(file_put_contents($filename, 'test'));
        $timestamp = strtotime('2020-02-03T04:05:06Z');
        self::assertNotFalse($timestamp);
        self::assertTrue(touch($filename, $timestamp));

        $media = $this->makeMedia(
            id: 6,
            path: $filename,
        );

        $result = $normalizer->extract($media->getPath(), $media);

        self::assertSame(TimeSource::FILE_MTIME, $result->getTimeSource());
        self::assertSame(0.2, $result->getTzConfidence());

        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    #[Test]
    public function logsFilesystemMismatchWhenBeyondThreshold(): void
    {
        $normalizer = $this->createNormalizer(
            defaultTimezone: 'UTC',
            priority: ['filename'],
            maxOffsetMinutes: 5,
        );

        $filename = sprintf('%s/IMG_20240101_101112.jpg', sys_get_temp_dir());
        if (file_exists($filename)) {
            unlink($filename);
        }

        self::assertNotFalse(file_put_contents($filename, 't'));
        $timestamp = strtotime('2024-01-01T00:00:00Z');
        self::assertNotFalse($timestamp);
        self::assertTrue(touch($filename, $timestamp));

        $media = $this->makeMedia(
            id: 7,
            path: $filename,
            takenAt: '2024-01-02T12:00:00+00:00',
            configure: static function (Media $item): void {
                $item->setTimeSource(TimeSource::EXIF);
            },
        );

        $result = $normalizer->extract($media->getPath(), $media);

        self::assertSame(TimeSource::EXIF, $result->getTimeSource());
        self::assertStringContainsString('Warnung: Aufnahmezeit weicht vom Dateisystem', (string) $result->getIndexLog());

        if (file_exists($filename)) {
            unlink($filename);
        }
    }

    private function createNormalizer(
        ?DateTimeZone $resolvedTimezone = null,
        string $defaultTimezone = 'UTC',
        array $priority = ['filename', 'file_mtime'],
        int $maxOffsetMinutes = 720,
    ): TimeNormalizer {
        $timezoneResolver = new class($resolvedTimezone) implements TimezoneResolverInterface {
            public function __construct(private readonly ?DateTimeZone $timezone)
            {
            }

            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                if ($this->timezone instanceof DateTimeZone) {
                    return $this->timezone;
                }

                throw new RuntimeException('No timezone available');
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                if ($this->timezone instanceof DateTimeZone) {
                    return $this->timezone;
                }

                return new DateTimeZone('UTC');
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return null;
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                if ($this->timezone instanceof DateTimeZone) {
                    return $this->timezone->getName();
                }

                return 'UTC';
            }
        };

        $captureTimeResolver = new CaptureTimeResolver($timezoneResolver);

        return new TimeNormalizer(
            captureTimeResolver: $captureTimeResolver,
            defaultTimezone: $defaultTimezone,
            filenameDateParser: new FilenameDateParser(),
            sourcePriority: $priority,
            plausibilityThresholdMinutes: $maxOffsetMinutes,
        );
    }
}
