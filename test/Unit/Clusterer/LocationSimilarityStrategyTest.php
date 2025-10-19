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
use MagicSunday\Memories\Clusterer\LocationSimilarityStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\GeoCell;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use function explode;
use function sort;

final class LocationSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function clustersMediaByLocalityWithUnifiedMetadata(): void
    {
        $strategy = new LocationSimilarityStrategy(
            locationHelper: LocationHelper::createDefault(),
            radiusMeters: 220.0,
            minItemsPerPlace: 3,
            maxSpanHours: 6,
        );

        $museum = $this->makeLocation(
            providerPlaceId: 'berlin-museum',
            displayName: 'Neues Museum',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
            suburb: 'Mitte',
            configure: static function (Location $location): void {
                $location->setState('Berlin');
            },
        );

        $mediaItems = [
            $this->createMedia(801, '2025-02-01 09:00:00', 52.5201, 13.4049, $museum),
            $this->createMedia(802, '2025-02-01 09:35:00', 52.5202, 13.4051, $museum),
            $this->createMedia(803, '2025-02-01 10:10:00', 52.5203, 13.4052, $museum),
        ];

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];
        self::assertSame('location_similarity', $cluster->getAlgorithm());
        self::assertSame([801, 802, 803], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(3, $params['members_count']);
        self::assertSame($params['time_range'], $params['window_bounds']);

        $placeParts = $params['place_key'];
        self::assertIsString($placeParts);
        $actualTokens = $placeParts === '' ? [] : explode('|', $placeParts);
        sort($actualTokens);
        self::assertSame(['city:Berlin', 'country:Germany', 'suburb:Mitte'], $actualTokens);

        $centroid = $cluster->getCentroid();
        $expectedCell = GeoCell::fromPoint($centroid['lat'], $centroid['lon'], 7);
        self::assertSame($expectedCell, $params['centroid_cell7']);
    }

    #[Test]
    public function fallbackClusteringHonoursDistanceAndWindowThresholds(): void
    {
        $strategy = new LocationSimilarityStrategy(
            locationHelper: LocationHelper::createDefault(),
            radiusMeters: 240.0,
            minItemsPerPlace: 2,
            maxSpanHours: 8,
        );

        $mediaItems = [
            $this->createMedia(901, '2025-04-10 10:00:00', 48.1371, 11.5753, null),
            $this->createMedia(902, '2025-04-10 10:18:00', 48.1372, 11.5754, null),
            // Farther away -> should be treated as noise
            $this->createMedia(903, '2025-04-10 10:25:00', 48.1455, 11.5850, null),
            // Beyond the three hour window
            $this->createMedia(904, '2025-04-10 14:30:00', 48.1372, 11.5754, null),
        ];

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];
        self::assertSame([901, 902], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2, $params['members_count']);
        self::assertArrayNotHasKey('place_key', $params);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2025-04-10 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2025-04-10 10:18:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);
        self::assertSame($expectedRange, $params['window_bounds']);

        $centroid = $cluster->getCentroid();
        $expectedCell = GeoCell::fromPoint($centroid['lat'], $centroid['lon'], 7);
        self::assertSame($expectedCell, $params['centroid_cell7']);
    }

    private function createMedia(
        int $id,
        string $takenAt,
        float $lat,
        float $lon,
        ?Location $location,
        ?callable $configure = null,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('location-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: $configure,
        );
    }
}
