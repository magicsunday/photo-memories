<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\DaySummaryStage;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class InitializationStageTest extends TestCase
{
    #[Test]
    public function createsContinuousSummariesWithMetadata(): void
    {
        $timezoneResolver = new TimezoneResolver('UTC');
        $poiClassifier    = new PoiClassifier();
        $stage            = new InitializationStage($timezoneResolver, $poiClassifier, 'UTC');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $berlin = $this->makeLocation('berlin', 'Berlin, Germany', 52.5200, 13.4050, country: 'Germany');
        $tokyo  = $this->makeLocation(
            'tokyo',
            'Tokyo, Japan',
            35.6762,
            139.6503,
            country: 'Japan',
            configure: static function (Location $location): void {
                $location->setCategory('tourism');
                $location->setType('attraction');
            },
        );

        $items   = [];
        $items[] = $this->makeMediaFixture(
            1,
            'berlin.jpg',
            new DateTimeImmutable('2024-01-01 08:00:00', new DateTimeZone('Europe/Berlin')),
            $berlin->getLat(),
            $berlin->getLon(),
            $berlin,
            static function (Media $media): void {
                $media->setTimezoneOffsetMin(60);
            },
        );

        $items[] = $this->makeMediaFixture(
            2,
            'tokyo.jpg',
            new DateTimeImmutable('2024-01-03 09:00:00', new DateTimeZone('Asia/Tokyo')),
            $tokyo->getLat(),
            $tokyo->getLon(),
            $tokyo,
            static function (Media $media): void {
                $media->setTimezoneOffsetMin(540);
            },
        );

        $result = $stage->process($items, $home);

        self::assertArrayHasKey('2024-01-01', $result);
        self::assertArrayHasKey('2024-01-02', $result);
        self::assertArrayHasKey('2024-01-03', $result);

        self::assertFalse($result['2024-01-01']['isSynthetic']);
        self::assertTrue($result['2024-01-02']['isSynthetic']);
        self::assertSame(1, $result['2024-01-01']['photoCount']);
        self::assertSame(1, $result['2024-01-03']['tourismHits']);
        self::assertSame(60, $result['2024-01-01']['localTimezoneOffset']);
        self::assertSame(540, $result['2024-01-03']['localTimezoneOffset']);
    }
}
