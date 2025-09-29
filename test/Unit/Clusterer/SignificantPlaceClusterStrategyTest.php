<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\SignificantPlaceClusterStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class SignificantPlaceClusterStrategyTest extends TestCase
{
    #[Test]
    public function buildsClusterForFrequentPlace(): void
    {
        $helper   = new LocationHelper();
        $strategy = new SignificantPlaceClusterStrategy(
            locHelper: $helper,
            gridDegrees: 0.01,
            minVisitDays: 3,
            minItemsTotal: 5,
        );

        $location   = $this->createLocation('loc-berlin', 'Kaffeehaus');
        $mediaItems = [
            $this->createMedia(1, '2024-03-01 08:00:00', 52.5200, 13.4050, $location),
            $this->createMedia(2, '2024-03-01 09:00:00', 52.5202, 13.4052, $location),
            $this->createMedia(3, '2024-03-05 10:00:00', 52.5203, 13.4051, $location),
            $this->createMedia(4, '2024-03-05 11:00:00', 52.5204, 13.4053, $location),
            $this->createMedia(5, '2024-03-10 12:00:00', 52.5201, 13.4054, $location),
            $this->createMedia(6, '2024-04-01 09:00:00', 48.1, 11.6, $this->createLocation('loc-munich', 'Marienplatz')),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('significant_place', $cluster->getAlgorithm());
        self::assertSame([1, 2, 3, 4, 5], $cluster->getMembers());
        self::assertSame('Cafe Central', $cluster->getParams()['place']);
        self::assertSame('Cafe Central', $cluster->getParams()['poi_label']);
        self::assertSame('amenity', $cluster->getParams()['poi_category_key']);
        self::assertSame('cafe', $cluster->getParams()['poi_category_value']);
        self::assertSame(['cuisine' => 'coffee_shop'], $cluster->getParams()['poi_tags']);
        self::assertSame(3, $cluster->getParams()['visit_days']);
    }

    #[Test]
    public function enforcesMinimumVisitDays(): void
    {
        $helper   = new LocationHelper();
        $strategy = new SignificantPlaceClusterStrategy(
            locHelper: $helper,
            gridDegrees: 0.01,
            minVisitDays: 6,
            minItemsTotal: 5,
        );

        $location   = $this->createLocation('loc-berlin', 'Kaffeehaus');
        $mediaItems = [
            $this->createMedia(11, '2024-03-01 08:00:00', 52.5200, 13.4050, $location),
            $this->createMedia(12, '2024-03-01 09:00:00', 52.5202, 13.4052, $location),
            $this->createMedia(13, '2024-03-02 10:00:00', 52.5203, 13.4051, $location),
            $this->createMedia(14, '2024-03-03 11:00:00', 52.5201, 13.4053, $location),
            $this->createMedia(15, '2024-03-04 12:00:00', 52.5199, 13.4049, $location),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createLocation(string $id, string $displayName): Location
    {
        return $this->makeLocation(
            providerPlaceId: $id,
            displayName: $displayName,
            lat: 52.52,
            lon: 13.405,
            provider: 'provider',
            city: 'Berlin',
            country: 'Deutschland',
            configure: static function (Location $location): void {
                $location->setPois([
                    [
                        'name'  => 'Cafe Central',
                        'names' => [
                            'default'   => 'Cafe Central',
                            'localized' => [
                                'de' => 'Café Central',
                            ],
                            'alternates' => [],
                        ],
                        'categoryKey'   => 'amenity',
                        'categoryValue' => 'cafe',
                        'tags'          => ['cuisine' => 'coffee_shop'],
                    ],
                ]);
            },
        );
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon, Location $location): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('significant-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
        );
    }
}
