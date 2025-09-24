<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\BurstClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BurstClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsBurstWithinThresholds(): void
    {
        $strategy = new BurstClusterStrategy(
            maxGapSeconds: 60,
            maxMoveMeters: 30.0,
            minItems: 3,
        );

        $mediaItems = [
            $this->createMedia(2003, '2022-06-15 10:02:00', lat: 52.52010, lon: 13.40480),
            $this->createMedia(2001, '2022-06-15 10:00:10', lat: 52.52000, lon: 13.40470),
            $this->createMedia(2004, '2022-06-15 10:02:45', lat: 52.52020, lon: 13.40485),
            $this->createMedia(2002, '2022-06-15 10:01:05', lat: 52.52005, lon: 13.40475),
            // Gap too large for another burst
            $this->createMedia(2010, '2022-06-15 11:30:00', lat: 52.53000, lon: 13.41000),
            $this->createMedia(2011, '2022-06-15 11:32:00', lat: 52.53010, lon: 13.41010),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('burst', $cluster->getAlgorithm());

        $expectedMembers = [2001, 2002, 2003, 2004];
        self::assertSame($expectedMembers, $cluster->getMembers());

        $expectedRange = [
            'from' => (new DateTimeImmutable('2022-06-15 10:00:10', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2022-06-15 10:02:45', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.5200875, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(13.404775, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function returnsEmptyWhenBurstsAreTooSmall(): void
    {
        $strategy = new BurstClusterStrategy(
            maxGapSeconds: 45,
            maxMoveMeters: 25.0,
            minItems: 3,
        );

        $mediaItems = [
            $this->createMedia(2101, '2022-07-01 09:00:00', lat: 48.1370, lon: 11.5750),
            $this->createMedia(2102, '2022-07-01 09:05:00', lat: 48.1371, lon: 11.5751),
            $this->createMedia(2103, '2022-07-01 09:55:00', lat: 48.1372, lon: 11.5752),
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
            path: $path ?? __DIR__ . "/fixtures/burst-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
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

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
