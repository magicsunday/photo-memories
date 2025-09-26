<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\FirstVisitPlaceClusterStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FirstVisitPlaceClusterStrategyTest extends TestCase
{
    #[Test]
    public function picksEarliestEligibleVisitPerCell(): void
    {
        $helper = new LocationHelper();
        $strategy = new FirstVisitPlaceClusterStrategy(
            locHelper: $helper,
            gridDegrees: 0.01,
            timezone: 'Europe/Berlin',
            minItemsPerDay: 4,
            minNights: 1,
            maxNights: 2,
            minItemsTotal: 8,
        );

        $loc = $this->createLocation('loc-innsbruck', 'Innsbruck', 47.268, 11.392);
        $start = new DateTimeImmutable('2024-02-10 09:00:00', new DateTimeZone('UTC'));
        $items = [];

        for ($dayOffset = 0; $dayOffset < 2; $dayOffset++) {
            $day = $start->add(new DateInterval('P' . $dayOffset . 'D'));
            for ($i = 0; $i < 4; $i++) {
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
        for ($i = 0; $i < 4; $i++) {
            $items[] = $this->createMedia(
                1300 + $i,
                $later->add(new DateInterval('PT' . ($i * 600) . 'S')),
                47.269 + $i * 0.0004,
                11.393 + $i * 0.0004,
                $loc,
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('first_visit_place', $cluster->getAlgorithm());
        self::assertSame('Innsbruck', $cluster->getParams()['place']);
        self::assertSame(
            [1200, 1201, 1202, 1203, 1210, 1211, 1212, 1213],
            $cluster->getMembers()
        );
    }

    #[Test]
    public function enforcesMinimumItemsPerDay(): void
    {
        $helper = new LocationHelper();
        $strategy = new FirstVisitPlaceClusterStrategy(
            locHelper: $helper,
            gridDegrees: 0.01,
            timezone: 'Europe/Berlin',
            minItemsPerDay: 4,
            minNights: 1,
            maxNights: 2,
            minItemsTotal: 8,
        );

        $loc = $this->createLocation('loc-bolzano', 'Bolzano', 46.5, 11.35);
        $start = new DateTimeImmutable('2024-04-01 09:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($dayOffset = 0; $dayOffset < 2; $dayOffset++) {
            $day = $start->add(new DateInterval('P' . $dayOffset . 'D'));
            for ($i = 0; $i < 3; $i++) { // below per-day threshold
                $items[] = $this->createMedia(
                    1400 + ($dayOffset * 10) + $i,
                    $day->add(new DateInterval('PT' . ($i * 1200) . 'S')),
                    46.5 + $i * 0.0006,
                    11.35 + $i * 0.0006,
                    $loc,
                );
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createLocation(string $id, string $city, float $lat, float $lon): Location
    {
        $location = new Location('osm', $id, $city, $lat, $lon, 'cell-' . $id);
        $location->setCity($city);
        $location->setCountry('Austria');

        return $location;
    }

    private function createMedia(
        int $id,
        DateTimeImmutable $takenAt,
        float $lat,
        float $lon,
        Location $location
    ): Media {
        $media = new Media(
            path: __DIR__ . "/fixtures/first-visit-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);
        $media->setLocation($location);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
