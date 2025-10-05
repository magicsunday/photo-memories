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
use MagicSunday\Memories\Clusterer\AtHomeWeekdayClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AtHomeWeekdayClusterStrategyTest extends TestCase
{
    private const HOME_VERSION_HASH = 'test-home-version';

    #[Test]
    public function clustersConsecutiveWeekdaysWithinHomeRadius(): void
    {
        $strategy = new AtHomeWeekdayClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            homeLat: 52.5200,
            homeLon: 13.4050,
            homeRadiusMeters: 500.0,
            minHomeShare: 0.6,
            minItemsPerDay: 2,
            minItemsTotal: 4,
            homeVersionHash: self::HOME_VERSION_HASH,
        );

        $mediaItems = [
            $this->createMedia(101, '2023-04-03 07:30:00', 52.5201, 13.4051),
            $this->createMedia(102, '2023-04-03 08:00:00', 52.5199, 13.4049),
            $this->createMedia(103, '2023-04-03 09:15:00', 52.5300, 13.4050),
            $this->createMedia(104, '2023-04-04 07:45:00', 52.5202, 13.4052),
            $this->createMedia(105, '2023-04-04 08:20:00', 52.5198, 13.4048),
            $this->createMedia(106, '2023-04-08 10:00:00', 52.5200, 13.4050),
        ];

        $hash = $this->computeHomeConfigHash(52.5200, 13.4050, 500.0);
        $mediaItems[0]->setDistanceKmFromHome(0.12);
        $mediaItems[0]->setHomeConfigHash($hash);
        $mediaItems[1]->setDistanceKmFromHome(0.18);
        $mediaItems[1]->setHomeConfigHash($hash);
        $mediaItems[2]->setDistanceKmFromHome(1.80);
        $mediaItems[2]->setHomeConfigHash($hash);
        $mediaItems[3]->setDistanceKmFromHome(0.10);
        $mediaItems[3]->setHomeConfigHash($hash);
        $mediaItems[4]->setDistanceKmFromHome(0.16);
        $mediaItems[4]->setHomeConfigHash($hash);
        $mediaItems[5]->setDistanceKmFromHome(0.05);
        $mediaItems[5]->setHomeConfigHash($hash);

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('at_home_weekday', $cluster->getAlgorithm());
        self::assertSame([101, 102, 104, 105], $cluster->getMembers());

        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-04-03 07:30:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-04-04 08:20:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(13.405, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function requiresHomeLocationToBeConfigured(): void
    {
        $strategy = new AtHomeWeekdayClusterStrategy(localTimeHelper: new LocalTimeHelper('Europe/Berlin'));

        $mediaItems = [
            $this->createMedia(201, '2023-04-03 07:30:00', 52.5201, 13.4051),
            $this->createMedia(202, '2023-04-03 08:00:00', 52.5199, 13.4049),
            $this->createMedia(203, '2023-04-04 07:45:00', 52.5202, 13.4052),
            $this->createMedia(204, '2023-04-04 08:20:00', 52.5198, 13.4048),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt, ?float $lat, ?float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('media-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
        );
    }

    private function computeHomeConfigHash(float $homeLat, float $homeLon, float $homeRadiusMeters): string
    {
        return hash(
            'sha256',
            sprintf(
                '%.8f|%.8f|%.8f|%s',
                $homeLat,
                $homeLon,
                $homeRadiusMeters / 1000.0,
                self::HOME_VERSION_HASH,
            ),
        );
    }
}
