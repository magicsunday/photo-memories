<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\DayAlbumClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DayAlbumClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsMediaByLocalCalendarDay(): void
    {
        $strategy = new DayAlbumClusterStrategy(timezone: 'America/Los_Angeles', minItems: 2);

        $mediaItems = [
            $this->createMedia(101, '2022-06-01 23:30:00', 34.0522, -118.2437),
            $this->createMedia(102, '2022-06-02 00:15:00', 34.0524, -118.2439),
            // Falls below the minimum for its own day
            $this->createMedia(103, '2022-06-02 07:30:00', 34.0526, -118.2441),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('day_album', $cluster->getAlgorithm());
        self::assertSame([101, 102], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2022, $params['year']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2022-06-01 23:30:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2022-06-02 00:15:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(34.0523, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(-118.2438, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function returnsEmptyWhenNoDayMeetsMinimumItemCount(): void
    {
        $strategy = new DayAlbumClusterStrategy(timezone: 'UTC', minItems: 3);

        $mediaItems = [
            $this->createMedia(201, '2022-08-01 09:00:00', 52.5, 13.4),
            $this->createMedia(202, '2022-08-01 10:00:00', 52.5002, 13.4002),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/day-album-{$id}.jpg",
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
