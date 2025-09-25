<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\AnniversaryClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnniversaryClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersMediaSharingSameMonthDayAcrossYears(): void
    {
        $strategy = new AnniversaryClusterStrategy(new LocationHelper());

        $location = $this->createLocation(city: 'Berlin');
        $mediaItems = [
            $this->createMedia(
                id: 10,
                takenAt: '2020-05-10 09:00:00',
                lat: 52.5200,
                lon: 13.4050,
                location: $location,
            ),
            $this->createMedia(
                id: 11,
                takenAt: '2021-05-10 10:15:00',
                lat: 52.5205,
                lon: 13.4045,
                location: $location,
            ),
            $this->createMedia(
                id: 12,
                takenAt: '2022-05-10 08:30:00',
                lat: 52.5195,
                lon: 13.4055,
                location: $location,
            ),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];
        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('anniversary', $cluster->getAlgorithm());
        self::assertSame([10, 11, 12], $cluster->getMembers());

        $expectedRange = [
            'from' => (new DateTimeImmutable('2020-05-10 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2022-05-10 08:30:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);
        self::assertSame('Berlin', $cluster->getParams()['place']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52, $centroid['lat'], 0.0005);
        self::assertEqualsWithDelta(13.405, $centroid['lon'], 0.0005);
    }

    #[Test]
    public function skipsGroupsWithLessThanThreeMedia(): void
    {
        $strategy = new AnniversaryClusterStrategy(new LocationHelper());

        $location = $this->createLocation(city: 'Hamburg');
        $mediaItems = [
            $this->createMedia(
                id: 20,
                takenAt: '2020-03-15 12:00:00',
                lat: 53.5511,
                lon: 9.9937,
                location: $location,
            ),
            $this->createMedia(
                id: 21,
                takenAt: '2021-03-15 12:00:00',
                lat: 53.5512,
                lon: 9.9936,
                location: $location,
            ),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertSame([], $clusters);
    }

    #[Test]
    public function enforcesMinimumDistinctYears(): void
    {
        $strategy = new AnniversaryClusterStrategy(new LocationHelper(), minItems: 3, minDistinctYears: 3);

        $location = $this->createLocation(city: 'Munich');
        $mediaItems = [
            $this->createMedia(
                id: 30,
                takenAt: '2020-07-04 09:00:00',
                lat: 48.1371,
                lon: 11.5754,
                location: $location,
            ),
            $this->createMedia(
                id: 31,
                takenAt: '2021-07-04 10:00:00',
                lat: 48.1372,
                lon: 11.5755,
                location: $location,
            ),
            $this->createMedia(
                id: 32,
                takenAt: '2021-07-04 11:00:00',
                lat: 48.1373,
                lon: 11.5756,
                location: $location,
            ),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));

        $mediaItems[] = $this->createMedia(
            id: 33,
            takenAt: '2022-07-04 09:30:00',
            lat: 48.1374,
            lon: 11.5753,
            location: $location,
        );

        $clusters = $strategy->cluster($mediaItems);
        self::assertCount(1, $clusters);
        self::assertSame([30, 31, 32, 33], $clusters[0]->getMembers());
    }

    #[Test]
    public function limitsClustersToConfiguredMaximum(): void
    {
        $strategy = new AnniversaryClusterStrategy(new LocationHelper(), minItems: 3, minDistinctYears: 1, maxClusters: 1);

        $location = $this->createLocation(city: 'Cologne');
        $mediaItems = [
            $this->createMedia(
                id: 40,
                takenAt: '2018-01-01 08:00:00',
                lat: 50.9375,
                lon: 6.9603,
                location: $location,
            ),
            $this->createMedia(
                id: 41,
                takenAt: '2019-01-01 08:15:00',
                lat: 50.9376,
                lon: 6.9604,
                location: $location,
            ),
            $this->createMedia(
                id: 42,
                takenAt: '2020-01-01 08:30:00',
                lat: 50.9377,
                lon: 6.9605,
                location: $location,
            ),
            $this->createMedia(
                id: 43,
                takenAt: '2021-01-01 08:45:00',
                lat: 50.9378,
                lon: 6.9606,
                location: $location,
            ),
            $this->createMedia(
                id: 44,
                takenAt: '2018-02-02 08:00:00',
                lat: 50.9375,
                lon: 6.9603,
                location: $location,
            ),
            $this->createMedia(
                id: 45,
                takenAt: '2019-02-02 08:15:00',
                lat: 50.9376,
                lon: 6.9604,
                location: $location,
            ),
            $this->createMedia(
                id: 46,
                takenAt: '2020-02-02 08:30:00',
                lat: 50.9377,
                lon: 6.9605,
                location: $location,
            ),
        ];

        $clusters = $strategy->cluster($mediaItems);
        self::assertCount(1, $clusters);
        self::assertSame([40, 41, 42, 43], $clusters[0]->getMembers());
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon, Location $location): Media
    {
        $media = new Media(
            path: __DIR__."/fixtures/media-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')));
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);
        $media->setLocation($location);

        return $media;
    }

    private function createLocation(string $city): Location
    {
        $location = new Location(
            provider: 'osm',
            providerPlaceId: 'place-'.$city,
            displayName: $city,
            lat: 0.0,
            lon: 0.0,
            cell: 'cell-'.$city,
        );

        $location->setCity($city);
        $location->setCountry('Germany');

        return $location;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
