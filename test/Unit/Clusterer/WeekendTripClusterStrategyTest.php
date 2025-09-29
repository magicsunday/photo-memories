<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\WeekendTripClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class WeekendTripClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersWeekendTripAwayFromHome(): void
    {
        $helper = new LocationHelper();
        $strategy = new WeekendTripClusterStrategy(
            locHelper: $helper,
            homeLat: 52.5200,
            homeLon: 13.4050,
            minAwayKm: 50.0,
            minNights: 1,
            minItemsPerTrip: 3,
        );

        $location = $this->createLocation('munich', 'Munich', 48.137, 11.575);
        $media = [
            $this->createMedia(700, '2024-04-19 16:00:00', $location),
            $this->createMedia(701, '2024-04-20 10:00:00', $location),
            $this->createMedia(702, '2024-04-21 11:00:00', $location),
        ];

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('weekend_trip', $cluster->getAlgorithm());
        self::assertSame([700, 701, 702], $cluster->getMembers());
        self::assertSame('Munich', $cluster->getParams()['place']);
        self::assertGreaterThanOrEqual(1, $cluster->getParams()['nights']);
        self::assertArrayHasKey('distance_km', $cluster->getParams());
    }

    #[Test]
    public function rejectsTripsTooCloseToHome(): void
    {
        $helper = new LocationHelper();
        $strategy = new WeekendTripClusterStrategy(
            locHelper: $helper,
            homeLat: 52.5200,
            homeLon: 13.4050,
            minAwayKm: 80.0,
            minNights: 1,
            minItemsPerTrip: 3,
        );

        $location = $this->createLocation('potsdam', 'Potsdam', 52.400, 13.050);
        $items = [
            $this->createMedia(710, '2024-05-17 16:00:00', $location),
            $this->createMedia(711, '2024-05-18 10:00:00', $location),
            $this->createMedia(712, '2024-05-19 11:00:00', $location),
        ];

        self::assertSame([], $strategy->cluster($items));
    }

    private function createLocation(string $id, string $city, float $lat, float $lon): Location
    {
        return $this->makeLocation(
            providerPlaceId: $id,
            displayName: $city,
            lat: $lat,
            lon: $lon,
            city: $city,
            country: 'Germany',
        );
    }

    private function createMedia(int $id, string $takenAt, Location $location): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('weekend-%d.jpg', $id),
            takenAt: $takenAt,
            location: $location,
        );
    }

}
