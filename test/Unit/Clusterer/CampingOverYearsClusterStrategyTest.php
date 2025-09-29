<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\CampingOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class CampingOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesBestCampingRunsAcrossYears(): void
    {
        $strategy = new CampingOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
            minNights: 1,
            maxNights: 4,
            minYears: 3,
            minItemsTotal: 12,
        );

        $mediaItems = [
            // 2021 qualifying run (3 days)
            $this->createMedia(3101, '2021-07-01 09:00:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3102, '2021-07-01 18:00:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3103, '2021-07-02 09:30:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3104, '2021-07-02 19:00:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3105, '2021-07-03 10:00:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3106, '2021-07-03 17:00:00', lat: 47.5000, lon: 11.0000),
            // Alternative 2021 day with too few photos
            $this->createMedia(3110, '2021-08-15 12:00:00', filename: 'hike-2021.jpg'),
            // 2022 qualifying run (3 days)
            $this->createMedia(3201, '2022-07-05 08:45:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3202, '2022-07-05 19:30:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3203, '2022-07-06 09:15:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3204, '2022-07-06 18:15:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3205, '2022-07-07 09:45:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3206, '2022-07-07 17:45:00', lat: 47.5000, lon: 11.0000),
            // 2023 qualifying run (3 days)
            $this->createMedia(3301, '2023-07-02 08:30:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3302, '2023-07-02 19:20:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3303, '2023-07-03 08:55:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3304, '2023-07-03 18:10:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3305, '2023-07-04 09:05:00', lat: 47.5000, lon: 11.0000),
            $this->createMedia(3306, '2023-07-04 18:05:00', lat: 47.5000, lon: 11.0000),
            // Non-camping media that should be ignored
            $this->createMedia(3310, '2023-07-10 12:00:00', filename: 'beach-2023.jpg'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('camping_over_years', $cluster->getAlgorithm());

        $expectedMembers = [
            3101, 3102, 3103, 3104, 3105, 3106,
            3201, 3202, 3203, 3204, 3205, 3206,
            3301, 3302, 3303, 3304, 3305, 3306,
        ];
        self::assertSame($expectedMembers, $cluster->getMembers());

        $expectedYears = [2021, 2022, 2023];
        self::assertSame($expectedYears, $cluster->getParams()['years']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2021-07-01 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-07-04 18:05:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(47.5, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(11.0, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function skipsWhenTooFewYearsQualify(): void
    {
        $strategy = new CampingOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
            minNights: 1,
            maxNights: 4,
            minYears: 3,
            minItemsTotal: 12,
        );

        $mediaItems = [
            // Only two years of data, not enough for the minimum
            $this->createMedia(4101, '2021-07-01 10:00:00'),
            $this->createMedia(4102, '2021-07-01 16:00:00'),
            $this->createMedia(4103, '2021-07-02 10:00:00'),
            $this->createMedia(4104, '2021-07-02 16:00:00'),
            $this->createMedia(4201, '2022-07-05 09:00:00'),
            $this->createMedia(4202, '2022-07-05 18:00:00'),
            $this->createMedia(4203, '2022-07-06 09:00:00'),
            $this->createMedia(4204, '2022-07-06 18:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(
        int $id,
        string $takenAt,
        ?float $lat = null,
        ?float $lon = null,
        ?string $filename = null,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: $filename ?? sprintf('camping-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            size: 2048,
        );
    }

}
