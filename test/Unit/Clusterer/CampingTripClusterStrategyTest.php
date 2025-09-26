<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\CampingTripClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class CampingTripClusterStrategyTest extends TestCase
{
    #[Test]
    public function buildsClusterFromConsecutiveCampingDays(): void
    {
        $strategy = new CampingTripClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
            minNights: 1,
            maxNights: 5,
            minItemsTotal: 6,
        );

        $mediaItems = [
            // Day 1
            $this->createMedia(5101, '2022-08-10 08:30:00', lat: 46.8000, lon: 10.5000),
            $this->createMedia(5102, '2022-08-10 12:15:00', lat: 46.8000, lon: 10.5000),
            $this->createMedia(5103, '2022-08-10 19:45:00', lat: 46.8000, lon: 10.5000),
            // Day 2
            $this->createMedia(5111, '2022-08-11 09:00:00', lat: 46.8000, lon: 10.5000),
            $this->createMedia(5112, '2022-08-11 18:30:00', lat: 46.8000, lon: 10.5000),
            // Day 3
            $this->createMedia(5121, '2022-08-12 10:15:00', lat: 46.8000, lon: 10.5000),
            $this->createMedia(5122, '2022-08-12 17:45:00', lat: 46.8000, lon: 10.5000),
            // Sparse day that should end the run
            $this->createMedia(5201, '2022-08-14 09:00:00', path: __DIR__.'/fixtures/camping-solo.jpg'),
            // Non-camping item ignored
            $this->createMedia(5301, '2022-08-10 09:00:00', path: __DIR__.'/fixtures/hotel-2022.jpg'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('camping_trip', $cluster->getAlgorithm());

        $expectedMembers = [5101, 5102, 5103, 5111, 5112, 5121, 5122];
        self::assertSame($expectedMembers, $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2, $params['nights']);
        $expectedRange = [
            'from' => (new DateTimeImmutable('2022-08-10 08:30:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2022-08-12 17:45:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(46.8, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(10.5, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function returnsEmptyWhenRunTooSmall(): void
    {
        $strategy = new CampingTripClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
            minNights: 1,
            maxNights: 5,
            minItemsTotal: 6,
        );

        $mediaItems = [
            $this->createMedia(6101, '2022-09-01 09:00:00'),
            $this->createMedia(6102, '2022-09-02 09:00:00'),
            $this->createMedia(6103, '2022-09-03 09:00:00'),
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
            path: $path ?? __DIR__ . "/fixtures/camping-trip-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1536,
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