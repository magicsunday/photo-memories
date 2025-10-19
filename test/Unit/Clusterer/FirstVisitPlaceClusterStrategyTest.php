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
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\FirstVisitPlaceClusterStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class FirstVisitPlaceClusterStrategyTest extends TestCase
{
    #[Test]
    public function picksEarliestEligibleVisitPerCell(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = new FirstVisitPlaceClusterStrategy(
            locationHelper: $helper,
            gridDegrees: 0.01,
            timezone: 'Europe/Berlin',
            minItemsPerDay: 4,
            minNights: 1,
            maxNights: 2,
            minItemsTotal: 8,
        );

        $loc   = $this->createLocation('loc-innsbruck', 'Innsbruck', 47.268, 11.392);
        $start = new DateTimeImmutable('2024-02-10 09:00:00', new DateTimeZone('UTC'));
        $items = [];

        for ($dayOffset = 0; $dayOffset < 2; ++$dayOffset) {
            $day = $start->add(new DateInterval('P' . $dayOffset . 'D'));
            for ($i = 0; $i < 4; ++$i) {
                $items[] = $this->createMedia(
                    1200 + ($dayOffset * 10) + $i,
                    $day->add(new DateInterval('PT' . ($i * 900) . 'S')),
                    47.268 + $i * 0.0005,
                    11.392 + $i * 0.0005,
                    $loc,
                );
            }
        }

        // Later revisit in same cell should be ignored
        $later = new DateTimeImmutable('2024-03-05 10:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 4; ++$i) {
            $items[] = $this->createMedia(
                1300 + $i,
                $later->add(new DateInterval('PT' . ($i * 600) . 'S')),
                47.269 + $i * 0.0004,
                11.393 + $i * 0.0004,
                $loc,
            );
        }

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('first_visit_place', $cluster->getAlgorithm());
        $params = $cluster->getParams();

        self::assertArrayHasKey('place', $params);
        self::assertArrayHasKey('place_city', $params);
        self::assertArrayHasKey('place_country', $params);
        self::assertArrayHasKey('place_location', $params);

        self::assertSame('Innsbruck', $params['place']);
        self::assertSame('Innsbruck', $params['place_city']);
        self::assertSame('Austria', $params['place_country']);
        self::assertSame('Innsbruck, Austria', $params['place_location']);
        self::assertSame(
            [1200, 1201, 1202, 1203, 1210, 1211, 1212, 1213],
            $cluster->getMembers()
        );
    }

    #[Test]
    public function enforcesMinimumItemsPerDay(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = new FirstVisitPlaceClusterStrategy(
            locationHelper: $helper,
            gridDegrees: 0.01,
            timezone: 'Europe/Berlin',
            minItemsPerDay: 4,
            minNights: 1,
            maxNights: 2,
            minItemsTotal: 8,
        );

        $loc   = $this->createLocation('loc-bolzano', 'Bolzano', 46.5, 11.35);
        $start = new DateTimeImmutable('2024-04-01 09:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($dayOffset = 0; $dayOffset < 2; ++$dayOffset) {
            $day = $start->add(new DateInterval('P' . $dayOffset . 'D'));
            for ($i = 0; $i < 3; ++$i) { // below per-day threshold
                $items[] = $this->createMedia(
                    1400 + ($dayOffset * 10) + $i,
                    $day->add(new DateInterval('PT' . ($i * 1200) . 'S')),
                    46.5 + $i * 0.0006,
                    11.35 + $i * 0.0006,
                    $loc,
                );
            }
        }

        self::assertSame([], $strategy->draft($items, Context::fromScope($items)));
    }

    private function createLocation(string $id, string $city, float $lat, float $lon): Location
    {
        return $this->makeLocation(
            providerPlaceId: $id,
            displayName: $city,
            lat: $lat,
            lon: $lon,
            city: $city,
            country: 'Austria',
        );
    }

    private function createMedia(
        int $id,
        DateTimeImmutable $takenAt,
        float $lat,
        float $lon,
        Location $location,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('first-visit-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
        );
    }
}
