<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Service;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class TimezoneResolverTest extends TestCase
{
    #[Test]
    public function resolveMediaTimezonePrefersExplicitOffset(): void
    {
        $resolver = new TimezoneResolver('Europe/Berlin');
        $takenAt  = new DateTimeImmutable('2024-04-05 10:15:00', new DateTimeZone('UTC'));
        $media    = $this->makeMediaFixture(1, 'offset-media.jpg', $takenAt, 52.0, 13.0);
        $media->setTimezoneOffsetMin(-120);

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $timezone = $resolver->resolveMediaTimezone($media, $takenAt, $home);
        self::assertSame('-02:00', $timezone->getName());
    }

    #[Test]
    public function determineLocalTimezoneOffsetFallsBackToHome(): void
    {
        $resolver = new TimezoneResolver('Europe/Berlin');
        $home     = [
            'lat'             => 48.0,
            'lon'             => 11.0,
            'radius_km'       => 10.0,
            'country'         => 'de',
            'timezone_offset' => 120,
        ];

        $offset = $resolver->determineLocalTimezoneOffset([], $home);
        self::assertSame(120, $offset);

        $voted = $resolver->determineLocalTimezoneOffset([60 => 1, -300 => 5], $home);
        self::assertSame(-300, $voted);
    }

    #[Test]
    public function determineLocalTimezoneIdentifierHonoursVotesAndFallbacks(): void
    {
        $resolver = new TimezoneResolver('Europe/Berlin');
        $home     = [
            'lat'             => 34.0,
            'lon'             => -118.0,
            'radius_km'       => 20.0,
            'country'         => 'us',
            'timezone_offset' => -480,
        ];

        $identifier = $resolver->determineLocalTimezoneIdentifier(['America/Los_Angeles' => 3], $home, null);
        self::assertSame('America/Los_Angeles', $identifier);

        $fallback = $resolver->determineLocalTimezoneIdentifier([], $home, -300);
        self::assertSame('-05:00', $fallback);

        $homeDefault = $resolver->determineLocalTimezoneIdentifier([], $home, null);
        self::assertSame('-08:00', $homeDefault);
    }

    #[Test]
    public function resolveSummaryTimezoneUsesIdentifier(): void
    {
        $resolver = new TimezoneResolver('Europe/Berlin');
        $home     = [
            'lat'             => 35.0,
            'lon'             => 139.0,
            'radius_km'       => 15.0,
            'country'         => 'jp',
            'timezone_offset' => 540,
        ];

        $summary = [
            'localTimezoneIdentifier' => 'Asia/Tokyo',
            'localTimezoneOffset'     => 540,
            'timezoneOffsets'         => [540 => 3],
        ];

        $timezone = $resolver->resolveSummaryTimezone($summary, $home);
        self::assertSame('Asia/Tokyo', $timezone->getName());
    }
}
