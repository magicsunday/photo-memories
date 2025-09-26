<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\TimeSimilarityStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class TimeSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function clustersMediaWithinGapAndLocalityBoundaries(): void
    {
        $helper = new LocationHelper();
        $strategy = new TimeSimilarityStrategy(
            locHelper: $helper,
            maxGapSeconds: 1800,
            minItemsPerBucket: 3,
        );

        $berlin = $this->createLocation(
            providerPlaceId: 'berlin-city',
            displayName: 'Berlin',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
        );
        $munich = $this->createLocation(
            providerPlaceId: 'munich-city',
            displayName: 'Munich',
            lat: 48.1371,
            lon: 11.5753,
            city: 'Munich',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(1001, '2023-07-01 09:00:00', 52.5201, 13.4049, $berlin),
            $this->createMedia(1002, '2023-07-01 09:20:00', 52.5202, 13.4050, $berlin),
            $this->createMedia(1003, '2023-07-01 09:40:00', 52.5203, 13.4051, $berlin),
            // Gap larger than max gap -> split bucket
            $this->createMedia(1004, '2023-07-01 12:30:00', 52.5204, 13.4052, $berlin),
            // Same day but different locality should not join previous bucket
            $this->createMedia(1005, '2023-07-01 12:40:00', 48.1372, 11.5754, $munich),
            $this->createMedia(1006, '2023-07-01 12:55:00', 48.1373, 11.5755, $munich),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('time_similarity', $cluster->getAlgorithm());
        self::assertSame([1001, 1002, 1003], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame('Berlin', $params['place']);
        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-07-01 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-07-01 09:40:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.5202, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(13.4050, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function returnsEmptyWhenNoBucketMeetsMinimumItems(): void
    {
        $strategy = new TimeSimilarityStrategy(
            locHelper: new LocationHelper(),
            maxGapSeconds: 900,
            minItemsPerBucket: 4,
        );

        $location = $this->createLocation(
            providerPlaceId: 'hamburg-city',
            displayName: 'Hamburg',
            lat: 53.5511,
            lon: 9.9937,
            city: 'Hamburg',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(1101, '2023-08-10 08:00:00', 53.5510, 9.9936, $location),
            $this->createMedia(1102, '2023-08-10 08:12:00', 53.5511, 9.9937, $location),
            $this->createMedia(1103, '2023-08-10 08:25:00', 53.5512, 9.9938, $location),
            // Splits due to time gap
            $this->createMedia(1104, '2023-08-10 10:00:00', 53.5513, 9.9939, $location),
            $this->createMedia(1105, '2023-08-10 10:10:00', 53.5514, 9.9940, $location),
            $this->createMedia(1106, '2023-08-10 10:20:00', 53.5515, 9.9941, $location),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(
        int $id,
        string $takenAt,
        float $lat,
        float $lon,
        Location $location
    ): Media {
        $media = new Media(
            path: __DIR__ . "/fixtures/time-{$id}.jpg",
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

    private function createLocation(
        string $providerPlaceId,
        string $displayName,
        float $lat,
        float $lon,
        ?string $city = null,
        ?string $country = null,
    ): Location {
        $location = new Location(
            provider: 'osm',
            providerPlaceId: $providerPlaceId,
            displayName: $displayName,
            lat: $lat,
            lon: $lon,
            cell: 'cell-' . $providerPlaceId,
        );

        $location->setCity($city);
        $location->setCountry($country);

        return $location;
    }

}
