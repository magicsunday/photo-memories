<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\TransitTravelDayClusterStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class TransitTravelDayClusterStrategyTest extends TestCase
{
    #[Test]
    public function marksDaysWithSufficientTravelDistance(): void
    {
        $strategy = new TransitTravelDayClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            minTravelKm: 50.0,
            minItemsPerDay: 3,
        );

        $day    = new DateTimeImmutable('2024-07-01 06:00:00', new DateTimeZone('UTC'));
        $points = [
            [50.0, 8.0],
            [50.3, 8.5],
            [50.6, 9.0],
            [50.9, 9.5],
            [51.2, 10.0],
        ];

        $items = [];

        $frankfurt = $this->makeLocation(
            providerPlaceId: 'travel-frankfurt',
            displayName: 'Frankfurt am Main',
            lat: 50.1109,
            lon: 8.6821,
            city: 'Frankfurt',
            country: 'Germany',
            configure: static function (Location $location): void {
                $location->setState('Hessen');
            },
        );

        foreach ($points as $idx => [$lat, $lon]) {
            $items[] = $this->createMedia(
                2300 + $idx,
                $day->add(new DateInterval('PT' . ($idx * 1800) . 'S')),
                $lat,
                $lon,
                $frankfurt,
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('transit_travel_day', $cluster->getAlgorithm());
        self::assertSame(range(2300, 2304), $cluster->getMembers());
        self::assertGreaterThanOrEqual(50.0, $cluster->getParams()['distance_km']);
        self::assertArrayHasKey('place', $cluster->getParams());
        self::assertNotSame('', $cluster->getParams()['place']);
        self::assertSame('Frankfurt', $cluster->getParams()['place_city']);
        self::assertSame('Germany', $cluster->getParams()['place_country']);
        self::assertSame('default', $cluster->getParams()['travel_profile']);
        self::assertSame(3, $cluster->getParams()['travel_thresholds']['min_items_per_day']);
    }

    #[Test]
    public function skipsDaysBelowDistance(): void
    {
        $strategy = new TransitTravelDayClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
        );

        $day    = new DateTimeImmutable('2024-07-02 06:00:00', new DateTimeZone('UTC'));
        $points = [
            [50.0, 8.0],
            [50.01, 8.01],
            [50.02, 8.02],
            [50.03, 8.03],
            [50.04, 8.04],
        ];

        $items = [];
        foreach ($points as $idx => [$lat, $lon]) {
            $items[] = $this->createMedia(2400 + $idx, $day->add(new DateInterval('PT' . ($idx * 600) . 'S')), $lat, $lon);
        }

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function appliesThresholdProfileOverrides(): void
    {
        $strategy = new TransitTravelDayClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            profileThresholds: [
                'adventurer' => [
                    'min_travel_km'         => 40.0,
                    'min_items_per_day'     => 3,
                    'min_segment_speed_mps' => 3.0,
                    'min_fast_segments'     => 1,
                ],
            ],
            activeProfile: 'adventurer',
        );

        $day    = new DateTimeImmutable('2024-07-03 06:00:00', new DateTimeZone('UTC'));
        $points = [
            [50.0, 8.0],
            [50.2, 8.2],
            [50.4, 8.4],
            [50.6, 8.6],
        ];

        $items = [];
        foreach ($points as $idx => [$lat, $lon]) {
            $items[] = $this->createMedia(2500 + $idx, $day->add(new DateInterval('PT' . ($idx * 1800) . 'S')), $lat, $lon);
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $params = $clusters[0]->getParams();
        self::assertSame('adventurer', $params['travel_profile']);
        self::assertSame(3, $params['travel_thresholds']['min_items_per_day']);
        self::assertSame(1, $params['travel_thresholds']['min_fast_segments']);
        self::assertGreaterThanOrEqual(40.0, $params['distance_km']);
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon, ?Location $location = null): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('transit-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
        );
    }
}
