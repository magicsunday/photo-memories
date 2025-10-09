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
use MagicSunday\Memories\Clusterer\AtHomeWeekendClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class AtHomeWeekendClusterStrategyTest extends TestCase
{
    private const HOME_VERSION_HASH = 'test-home-version';

    #[Test]
    public function clustersWeekendSessionsWithinHomeRadius(): void
    {
        $strategy = new AtHomeWeekendClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            homeLat: 52.5200,
            homeLon: 13.4050,
            homeRadiusMeters: 400.0,
            minHomeShare: 0.6,
            minItemsPerDay: 2,
            minItemsTotal: 4,
            homeVersionHash: self::HOME_VERSION_HASH,
        );

        $mediaItems = [
            // Saturday entries: two within radius, one outside
            $this->createMedia(301, '2023-04-08 08:00:00', 52.5201, 13.4051),
            $this->createMedia(302, '2023-04-08 09:30:00', 52.5199, 13.4049),
            $this->createMedia(303, '2023-04-08 10:15:00', 52.5400, 13.4200),
            // Sunday entries: two within radius, one outside
            $this->createMedia(304, '2023-04-09 11:00:00', 52.5202, 13.4052),
            $this->createMedia(305, '2023-04-09 12:20:00', 52.5198, 13.4048),
            $this->createMedia(306, '2023-04-09 13:00:00', 52.5500, 13.4500),
            // Weekday entry that should be ignored entirely
            $this->createMedia(307, '2023-04-10 07:45:00', 52.5203, 13.4053),
        ];

        $hash = $this->computeHomeConfigHash(52.5200, 13.4050, 400.0);
        $mediaItems[0]->setDistanceKmFromHome(0.08);
        $mediaItems[0]->setHomeConfigHash($hash);
        $mediaItems[1]->setDistanceKmFromHome(0.12);
        $mediaItems[1]->setHomeConfigHash($hash);
        $mediaItems[2]->setDistanceKmFromHome(2.50);
        $mediaItems[2]->setHomeConfigHash($hash);
        $mediaItems[3]->setDistanceKmFromHome(0.15);
        $mediaItems[3]->setHomeConfigHash($hash);
        $mediaItems[4]->setDistanceKmFromHome(0.11);
        $mediaItems[4]->setHomeConfigHash($hash);
        $mediaItems[5]->setDistanceKmFromHome(3.10);
        $mediaItems[5]->setHomeConfigHash($hash);
        $mediaItems[6]->setDistanceKmFromHome(0.05);
        $mediaItems[6]->setHomeConfigHash($hash);

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('at_home_weekend', $cluster->getAlgorithm());
        self::assertSame([301, 302, 304, 305], $cluster->getMembers());

        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-04-08 08:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-04-09 12:20:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);
        self::assertTrue($cluster->getParams()['isWeekend']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52, $centroid['lat'], 0.0002);
        self::assertEqualsWithDelta(13.405, $centroid['lon'], 0.0002);
    }

    #[Test]
    public function skipsDaysBelowHomeShareThreshold(): void
    {
        $strategy = new AtHomeWeekendClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            homeLat: 52.5200,
            homeLon: 13.4050,
            homeRadiusMeters: 300.0,
            minHomeShare: 0.7,
            minItemsPerDay: 2,
            minItemsTotal: 4,
            homeVersionHash: self::HOME_VERSION_HASH,
        );

        $mediaItems = [
            $this->createMedia(401, '2023-06-17 09:00:00', 52.5400, 13.4300),
            $this->createMedia(402, '2023-06-17 10:00:00', 52.5410, 13.4310),
            $this->createMedia(403, '2023-06-17 11:00:00', 52.5201, 13.4051),
            $this->createMedia(404, '2023-06-18 09:30:00', 52.5600, 13.4600),
            $this->createMedia(405, '2023-06-18 10:30:00', 52.5610, 13.4610),
            $this->createMedia(406, '2023-06-18 11:30:00', 52.5202, 13.4052),
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
            configure: static function (Media $media) use ($takenAt): void {
                $weekday = (int) (new DateTimeImmutable($takenAt, new DateTimeZone('UTC')))->format('N');
                $media->setFeatures([
                    'calendar' => ['isWeekend' => $weekday >= 6],
                ]);
            },
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
