<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\AnniversaryClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class AnniversaryClusterStrategyTest extends TestCase
{
    #[Test]
    public function keepsHighestScoringAnniversariesWithinLimit(): void
    {
        $strategy = new AnniversaryClusterStrategy(
            new LocationHelper(),
            minItemsPerAnniversary: 3,
            minDistinctYears: 2,
            maxClusters: 1,
        );

        $berlin = $this->createLocation('berlin');
        $munich = $this->createLocation('munich');

        $mediaItems = [
            // Jan-05 group (4 items across 3 years) -> higher score
            $this->createMedia(1001, '2019-01-05 09:00:00', $berlin, 52.5200, 13.4050),
            $this->createMedia(1002, '2020-01-05 09:05:00', $berlin, 52.5202, 13.4052),
            $this->createMedia(1003, '2020-01-05 09:06:00', $berlin, 52.5201, 13.4051),
            $this->createMedia(1004, '2021-01-05 09:10:00', $berlin, 52.5203, 13.4053),
            // Mar-10 group (3 items across 3 years) -> lower score, should be dropped by maxClusters
            $this->createMedia(1101, '2018-03-10 11:00:00', $munich, 48.1371, 11.5753),
            $this->createMedia(1102, '2019-03-10 11:05:00', $munich, 48.1372, 11.5754),
            $this->createMedia(1103, '2020-03-10 11:10:00', $munich, 48.1373, 11.5755),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('anniversary', $cluster->getAlgorithm());
        self::assertSame([1001, 1002, 1003, 1004], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame('Berlin', $params['place']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2019-01-05 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2021-01-05 09:10:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52015, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(13.40515, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function returnsEmptyWhenAnniversaryLacksDistinctYears(): void
    {
        $strategy = new AnniversaryClusterStrategy(
            new LocationHelper(),
            minItemsPerAnniversary: 3,
            minDistinctYears: 3,
            maxClusters: 0,
        );

        $location = $this->createLocation('hamburg');

        $mediaItems = [
            $this->createMedia(2001, '2022-07-04 10:00:00', $location, 53.5511, 9.9937),
            $this->createMedia(2002, '2022-07-04 10:02:00', $location, 53.5512, 9.9938),
            $this->createMedia(2003, '2023-07-04 10:05:00', $location, 53.5513, 9.9939),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function skipsGroupsBelowMinimumItemCount(): void
    {
        $strategy = new AnniversaryClusterStrategy(
            new LocationHelper(),
            minItemsPerAnniversary: 4,
            minDistinctYears: 2,
            maxClusters: 0,
        );

        $location = $this->createLocation('berlin');

        $mediaItems = [
            $this->createMedia(3001, '2020-09-15 12:00:00', $location, 52.5201, 13.4049),
            $this->createMedia(3002, '2021-09-15 12:05:00', $location, 52.5202, 13.4050),
            $this->createMedia(3003, '2022-09-15 12:10:00', $location, 52.5203, 13.4051),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(
        int $id,
        string $takenAt,
        Location $location,
        float $lat,
        float $lon,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('anniversary-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
        );
    }

    private function createLocation(string $key): Location
    {
        $city = match ($key) {
            'berlin' => 'Berlin',
            'munich' => 'Munich',
            default  => 'Hamburg',
        };

        return $this->makeLocation(
            providerPlaceId: $key,
            displayName: ucfirst($key),
            lat: 50.0,
            lon: 8.0,
            city: $city,
        );
    }
}
