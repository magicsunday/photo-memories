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
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\DayAlbumClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DayAlbumClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsMediaByLocalCalendarDay(): void
    {
        $strategy = new DayAlbumClusterStrategy(
            localTimeHelper: new LocalTimeHelper('America/Los_Angeles'),
            minItemsPerDay: 2,
        );

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
        $strategy = new DayAlbumClusterStrategy(
            localTimeHelper: new LocalTimeHelper('UTC'),
            minItemsPerDay: 3,
        );

        $mediaItems = [
            $this->createMedia(201, '2022-08-01 09:00:00', 52.5, 13.4),
            $this->createMedia(202, '2022-08-01 10:00:00', 52.5002, 13.4002),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function honoursCapturedLocalWhenDifferentFromFallback(): void
    {
        $strategy = new DayAlbumClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            minItemsPerDay: 2,
        );

        $mediaItems = [
            $this->createShiftedMedia(501, '2023-01-01 07:30:00', '2022-12-31 23:30:00'),
            $this->createShiftedMedia(502, '2023-01-01 07:50:00', '2022-12-31 23:50:00'),
            $this->createShiftedMedia(503, '2023-01-01 08:30:00', '2023-01-01 00:30:00'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        self::assertSame([501, 502], $clusters[0]->getMembers());
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('day-album-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            configure: static function (Media $media): void {
                $media->setCapturedLocal(null);
                $media->setTimezoneOffsetMin(-420);
            },
        );
    }

    private function createShiftedMedia(int $id, string $takenAtUtc, string $capturedLocal): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('day-album-%d-shifted.jpg', $id),
            takenAt: $takenAtUtc,
            configure: static function (Media $media) use ($capturedLocal): void {
                $local = new DateTimeImmutable($capturedLocal, new DateTimeZone('America/Los_Angeles'));
                $media->setCapturedLocal($local);
                $media->setTimezoneOffsetMin(-480);
            },
        );
    }
}
