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

        $location = $this->createLocation(city: 'Berlin', suburb: 'Mitte');
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
        self::assertSame('Mitte, Berlin', $cluster->getParams()['place']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52, $centroid['lat'], 0.0005);
        self::assertEqualsWithDelta(13.405, $centroid['lon'], 0.0005);
    }

    #[Test]
    public function skipsGroupsWithLessThanThreeMedia(): void
    {
        $strategy = new AnniversaryClusterStrategy(new LocationHelper());

        $location = $this->createLocation(city: 'Hamburg', suburb: null);
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

    private function createLocation(string $city, ?string $suburb): Location
    {
        $location = new Location(
            provider: 'osm',
            providerPlaceId: 'place-'.$city,
            displayName: $suburb !== null ? $suburb.', '.$city : $city,
            lat: 0.0,
            lon: 0.0,
            cell: 'cell-'.$city,
        );

        $location->setCity($city);
        $location->setSuburb($suburb);
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
