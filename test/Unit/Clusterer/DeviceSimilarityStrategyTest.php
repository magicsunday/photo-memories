<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\DeviceSimilarityStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class DeviceSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function groupsMediaByDeviceDateAndLocation(): void
    {
        $strategy = new DeviceSimilarityStrategy(new LocationHelper(), minItemsPerGroup: 3);

        $berlin = $this->makeLocation(
            providerPlaceId: 'berlin-001',
            displayName: 'Berlin',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(301, '2023-05-01 09:00:00', 'Canon EOS R5', $berlin, 52.5200, 13.4050),
            $this->createMedia(302, '2023-05-01 10:30:00', 'Canon EOS R5', $berlin, 52.5203, 13.4052),
            $this->createMedia(303, '2023-05-01 11:45:00', 'Canon EOS R5', $berlin, 52.5205, 13.4054),
            // Different day, should not form a cluster because below minItems
            $this->createMedia(304, '2023-05-02 09:15:00', 'Canon EOS R5', $berlin, 52.5206, 13.4056),
            $this->createMedia(305, '2023-05-02 12:00:00', 'Canon EOS R5', $berlin, 52.5207, 13.4057),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('device_similarity', $cluster->getAlgorithm());
        self::assertSame([301, 302, 303], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame('Canon EOS R5', $params['device']);
        self::assertSame('Berlin', $params['place']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-05-01 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-05-01 11:45:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.5202666667, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(13.4052, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function returnsEmptyWhenGroupsDoNotReachMinimum(): void
    {
        $strategy = new DeviceSimilarityStrategy(new LocationHelper(), minItemsPerGroup: 4);

        $location = $this->makeLocation(
            providerPlaceId: 'munich-001',
            displayName: 'Munich',
            lat: 48.1371,
            lon: 11.5753,
            city: 'Munich',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(401, '2023-06-10 08:00:00', 'iPhone 14 Pro', $location, 48.1371, 11.5753),
            $this->createMedia(402, '2023-06-10 08:05:00', 'iPhone 14 Pro', $location, 48.1372, 11.5754),
            $this->createMedia(403, '2023-06-10 08:10:00', 'iPhone 14 Pro', $location, 48.1373, 11.5755),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(
        int $id,
        string $takenAt,
        ?string $camera,
        Location $location,
        float $lat,
        float $lon,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('device-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: static function (Media $media) use ($camera): void {
                $media->setCameraModel($camera);
            },
        );
    }

}
