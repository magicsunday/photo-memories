<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\TimeSimilarityStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\GeoCell;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class TimeSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function clustersMediaWithinThreeHourWindowsUsingGeoDbscan(): void
    {
        $strategy = new TimeSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            maxGapSeconds: 21600,
            minItemsPerBucket: 3,
        );

        $berlin = $this->makeLocation(
            providerPlaceId: 'berlin-city',
            displayName: 'Berlin',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(1001, '2025-01-05 09:00:00', 52.5201, 13.4050, $berlin),
            $this->createMedia(1002, '2025-01-05 09:40:00', 52.5202, 13.4051, $berlin),
            $this->createMedia(1003, '2025-01-05 10:15:00', 52.5203, 13.4052, $berlin),
            $this->createMedia(1004, '2025-01-05 14:00:00', 52.5205, 13.4053, $berlin),
            $this->createMedia(1005, '2025-01-05 14:20:00', 52.5206, 13.4054, $berlin),
            $this->createMedia(1006, '2025-01-05 14:40:00', 52.5207, 13.4055, $berlin),
        ];

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(2, $clusters);

        $morning = $clusters[0];
        self::assertSame('time_similarity', $morning->getAlgorithm());
        self::assertSame([1001, 1002, 1003], $morning->getMembers());

        $morningParams = $morning->getParams();
        self::assertSame(3, $morningParams['members_count']);
        self::assertSame($morningParams['time_range'], $morningParams['window_bounds']);

        $expectedMorningRange = [
            'from' => (new DateTimeImmutable('2025-01-05 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2025-01-05 10:15:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedMorningRange, $morningParams['time_range']);

        $morningCentroid = $morning->getCentroid();
        $expectedMorningCell = GeoCell::fromPoint($morningCentroid['lat'], $morningCentroid['lon'], 7);
        self::assertSame($expectedMorningCell, $morningParams['centroid_cell7']);

        $afternoon = $clusters[1];
        self::assertSame([1004, 1005, 1006], $afternoon->getMembers());

        $afternoonParams = $afternoon->getParams();
        self::assertSame(3, $afternoonParams['members_count']);

        $expectedAfternoonRange = [
            'from' => (new DateTimeImmutable('2025-01-05 14:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2025-01-05 14:40:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedAfternoonRange, $afternoonParams['time_range']);
        self::assertSame($expectedAfternoonRange, $afternoonParams['window_bounds']);
    }

    #[Test]
    public function excludesMediaOutsideDistanceThreshold(): void
    {
        $strategy = new TimeSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            maxGapSeconds: 7200,
            minItemsPerBucket: 2,
        );

        $location = $this->makeLocation(
            providerPlaceId: 'munich-city',
            displayName: 'Munich',
            lat: 48.1370,
            lon: 11.5750,
            city: 'Munich',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(2001, '2025-03-10 10:00:00', 48.1371, 11.5751, $location),
            $this->createMedia(2002, '2025-03-10 10:20:00', 48.1372, 11.5752, $location),
            // Approximately one kilometre away
            $this->createMedia(2003, '2025-03-10 10:40:00', 48.1450, 11.5850, $location),
        ];

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];
        self::assertSame([2001, 2002], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2, $params['members_count']);

        $centroid = $cluster->getCentroid();
        $expectedCell = GeoCell::fromPoint($centroid['lat'], $centroid['lon'], 7);
        self::assertSame($expectedCell, $params['centroid_cell7']);
    }

    private function createMedia(
        int $id,
        string $takenAt,
        float $lat,
        float $lon,
        Location $location,
        ?callable $configure = null,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('time-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: $configure,
        );
    }
}
