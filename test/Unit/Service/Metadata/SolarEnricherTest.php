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
use MagicSunday\Memories\Service\Metadata\SolarEnricher;
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SolarEnricherTest extends TestCase
{
    #[Test]
    public function detectsPolarDay(): void
    {
        $resolver = $this->createResolver('UTC');
        $enricher = new SolarEnricher($resolver, goldenMinutes: 60);

        $media = $this->makeMedia(
            id: 10,
            path: '/fixtures/polar-day.jpg',
            takenAt: new DateTimeImmutable('2024-06-21T12:00:00+00:00'),
            lat: 78.0,
            lon: 15.0,
        );
        $media->setTimezoneOffsetMin(0);

        $result = $enricher->extract($media->getPath(), $media);
        $features = $result->getFeatures();

        self::assertFalse($features['solar']['isGoldenHour'] ?? true);
        self::assertTrue($features['solar']['isPolarDay'] ?? false);
        self::assertFalse($features['solar']['isPolarNight'] ?? true);
    }

    #[Test]
    public function marksGoldenHourNearSunrise(): void
    {
        $resolver = $this->createResolver('Europe/Berlin');
        $enricher = new SolarEnricher($resolver, goldenMinutes: 60);

        $media = $this->makeMedia(
            id: 11,
            path: '/fixtures/golden-hour.jpg',
            takenAt: new DateTimeImmutable('2024-06-01T05:30:00+00:00'),
            lat: 48.1372,
            lon: 11.5756,
        );
        $media->setTimezoneOffsetMin(120);

        $result = $enricher->extract($media->getPath(), $media);
        $features = $result->getFeatures();

        self::assertTrue($features['solar']['isGoldenHour'] ?? false);
        self::assertFalse($features['solar']['isPolarDay'] ?? true);
        self::assertFalse($features['solar']['isPolarNight'] ?? true);
    }

    private function createResolver(string $timezone): CaptureTimeResolver
    {
        return new CaptureTimeResolver(new class($timezone) implements TimezoneResolverInterface {
            public function __construct(private readonly string $timezone)
            {
            }

            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                return new DateTimeZone($this->timezone);
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                return new DateTimeZone($this->timezone);
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return null;
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                return $this->timezone;
            }
        });
    }
}
