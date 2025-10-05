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
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\DaypartEnricher;
use MagicSunday\Memories\Service\Metadata\MetadataQaInspector;
use MagicSunday\Memories\Service\Metadata\SolarEnricher;
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MetadataQaInspectorTest extends TestCase
{
    #[Test]
    public function logsMissingFlagsWhenEnrichersSupport(): void
    {
        $inspector = $this->createInspector();
        $media     = $this->makeMediaFixture(
            id: 1,
            filename: 'qa-metadata.jpg',
            takenAt: new DateTimeImmutable('2023-06-01 08:00:00', new DateTimeZone('UTC')),
            lat: 52.5,
            lon: 13.4,
        );
        $media->setTimezoneOffsetMin(60);

        $inspector->inspect('/tmp/qa-metadata.jpg', $media);

        $log = $media->getIndexLog();
        self::assertNotNull($log);
        self::assertStringContainsString('daypart', (string) $log);
        self::assertStringContainsString('isGoldenHour', (string) $log);
    }

    #[Test]
    public function skipsLoggingWhenFlagsArePresent(): void
    {
        $inspector = $this->createInspector();
        $media     = $this->makeMediaFixture(
            id: 2,
            filename: 'qa-metadata-present.jpg',
            takenAt: new DateTimeImmutable('2023-06-02 08:00:00', new DateTimeZone('UTC')),
            lat: 52.6,
            lon: 13.5,
        );
        $media->setTimezoneOffsetMin(120);
        $media->setFeatures([
            'daypart'      => 'morning',
            'isGoldenHour' => true,
        ]);

        $inspector->inspect('/tmp/qa-metadata-present.jpg', $media);

        self::assertNull($media->getIndexLog());
    }

    private function createInspector(): MetadataQaInspector
    {
        $timezoneResolver = new class implements TimezoneResolverInterface {
            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return 0;
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                return 'UTC';
            }
        };

        $resolver = new CaptureTimeResolver($timezoneResolver);

        return new MetadataQaInspector(
            new DaypartEnricher($resolver),
            new SolarEnricher($resolver),
        );
    }
}
