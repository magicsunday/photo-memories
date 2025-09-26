<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\BeachOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class BeachOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersBestBeachDaysAcrossYears(): void
    {
        $strategy = new BeachOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
            minYears: 3,
            minItemsTotal: 6,
        );

        $mediaItems = [
            // 2019 best day (three qualifying beach photos)
            $this->createMedia(1101, '2019-08-05 09:00:00', lat: 36.5000, lon: -4.8800),
            $this->createMedia(1102, '2019-08-05 10:15:00', lat: 36.5000, lon: -4.8800),
            $this->createMedia(1103, '2019-08-05 11:45:00', lat: 36.5000, lon: -4.8800),
            // Competing 2019 day with fewer matches
            $this->createMedia(1110, '2019-08-20 12:00:00', path: __DIR__.'/fixtures/beach-2019-alt-1.jpg'),
            $this->createMedia(1111, '2019-08-20 13:00:00', path: __DIR__.'/fixtures/beach-2019-alt-2.jpg'),
            // 2020 best day (three qualifying beach photos)
            $this->createMedia(1201, '2020-08-12 09:30:00', lat: 36.5000, lon: -4.8800),
            $this->createMedia(1202, '2020-08-12 10:45:00', lat: 36.5000, lon: -4.8800),
            $this->createMedia(1203, '2020-08-12 12:15:00', lat: 36.5000, lon: -4.8800),
            // Competing 2020 day with fewer matches
            $this->createMedia(1210, '2020-09-01 08:00:00', path: __DIR__.'/fixtures/beach-2020-alt-1.jpg'),
            $this->createMedia(1211, '2020-09-01 08:30:00', path: __DIR__.'/fixtures/beach-2020-alt-2.jpg'),
            // 2021 best day (three qualifying beach photos)
            $this->createMedia(1301, '2021-08-20 09:45:00', lat: 36.5000, lon: -4.8800),
            $this->createMedia(1302, '2021-08-20 10:30:00', lat: 36.5000, lon: -4.8800),
            $this->createMedia(1303, '2021-08-20 11:30:00', lat: 36.5000, lon: -4.8800),
            // Non-beach media that should be ignored completely
            $this->createMedia(1310, '2021-08-21 09:00:00', path: __DIR__.'/fixtures/mountain-1310.jpg'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('beach_over_years', $cluster->getAlgorithm());

        $expectedMembers = [1101, 1102, 1103, 1201, 1202, 1203, 1301, 1302, 1303];
        self::assertSame($expectedMembers, $cluster->getMembers());
        self::assertNotContains(1210, $cluster->getMembers());
        self::assertNotContains(1211, $cluster->getMembers());

        $expectedYears = [2019, 2020, 2021];
        self::assertSame($expectedYears, $cluster->getParams()['years']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2019-08-05 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2021-08-20 11:30:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(36.5, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(-4.88, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function returnsEmptyWhenNotEnoughYearsQualify(): void
    {
        $strategy = new BeachOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
            minYears: 3,
            minItemsTotal: 6,
        );

        $mediaItems = [
            $this->createMedia(2101, '2020-07-10 09:00:00'),
            $this->createMedia(2102, '2020-07-10 10:00:00'),
            $this->createMedia(2201, '2021-07-15 09:30:00'),
            $this->createMedia(2202, '2021-07-15 10:30:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(
        int $id,
        string $takenAt,
        ?string $path = null,
        ?float $lat = null,
        ?float $lon = null,
    ): Media {
        $media = new Media(
            path: $path ?? __DIR__ . "/fixtures/beach-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 2048,
        );

        $this->assignId($media, $id);
        $media->setTakenAt(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')));

        if ($lat !== null) {
            $media->setGpsLat($lat);
        }

        if ($lon !== null) {
            $media->setGpsLon($lon);
        }

        return $media;
    }

}
