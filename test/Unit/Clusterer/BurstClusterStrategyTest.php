<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

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
    public function clustersSequentialShotsWithinGapAndDistance(): void
    {
        $strategy = new BurstClusterStrategy(maxGapSeconds: 120, maxMoveMeters: 100.0, minItemsPerBurst: 3);

        $mediaItems = [
            $this->createMedia(3001, '2023-04-15 10:01:10', 52.5201, 13.4051),
            $this->createMedia(3002, '2023-04-15 10:00:05', 52.5200, 13.4050),
            $this->createMedia(3003, '2023-04-15 10:02:20', 52.5202, 13.4052),
            $this->createMedia(3004, '2023-04-15 10:03:00', 52.5203, 13.4053),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('burst', $cluster->getAlgorithm());
        self::assertSame([3002, 3001, 3003, 3004], $cluster->getMembers());

        $params = $cluster->getParams();
        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-04-15 10:00:05', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-04-15 10:03:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52015, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(13.40515, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function breaksSequenceWhenGapExceedsThreshold(): void
    {
        $strategy = new BurstClusterStrategy(maxGapSeconds: 60, maxMoveMeters: 100.0, minItemsPerBurst: 3);

        $mediaItems = [
            $this->createMedia(4001, '2023-04-15 09:00:00', 40.7127, -74.0061),
            $this->createMedia(4002, '2023-04-15 09:00:30', 40.7128, -74.0060),
            $this->createMedia(4003, '2023-04-15 09:01:00', 40.7129, -74.0059),
            // Gap > 60s, so new session but below minItems and thus discarded
            $this->createMedia(4004, '2023-04-15 09:05:10', 40.7130, -74.0058),
            $this->createMedia(4005, '2023-04-15 09:06:20', 40.7131, -74.0057),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame([4001, 4002, 4003], $cluster->getMembers());
    }

    #[Test]
    public function returnsEmptyWhenNoBurstReachesMinimumSize(): void
    {
        $strategy = new BurstClusterStrategy(maxGapSeconds: 90, maxMoveMeters: 50.0, minItemsPerBurst: 4);

        $mediaItems = [
            $this->createMedia(5001, '2023-07-20 14:00:00', 34.0521, -118.2436),
            $this->createMedia(5002, '2023-07-20 14:00:40', 34.0522, -118.2435),
            $this->createMedia(5003, '2023-07-20 14:01:15', 34.0523, -118.2434),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/burst-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')));
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
