<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\HolidayEventClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HolidayEventClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsItemsByHolidayPerYear(): void
    {
        $strategy = new HolidayEventClusterStrategy(minItemsPerHoliday: 3);

        $mediaItems = [
            $this->createMedia(1, '2023-12-25 09:00:00', 52.5, 13.4),
            $this->createMedia(2, '2023-12-25 10:00:00', 52.5005, 13.401),
            $this->createMedia(3, '2023-12-25 12:00:00', 52.499, 13.402),
            $this->createMedia(4, '2024-12-25 09:30:00', 48.1, 11.6),
            $this->createMedia(5, '2024-12-25 10:30:00', 48.1005, 11.6005),
            $this->createMedia(6, '2024-12-25 11:00:00', 48.1009, 11.6010),
            $this->createMedia(7, '2023-05-01 09:15:00', 49.0, 12.0),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(2, $clusters);

        $first = $clusters[0];
        self::assertSame('holiday_event', $first->getAlgorithm());
        self::assertSame(2023, $first->getParams()['year']);
        self::assertSame('1. Weihnachtstag', $first->getParams()['holiday_name']);
        self::assertSame([1, 2, 3], $first->getMembers());

        $second = $clusters[1];
        self::assertSame(2024, $second->getParams()['year']);
        self::assertSame([4, 5, 6], $second->getMembers());
    }

    #[Test]
    public function filtersGroupsBelowMinimumCount(): void
    {
        $strategy = new HolidayEventClusterStrategy(minItemsPerHoliday: 4);

        $mediaItems = [
            $this->createMedia(11, '2023-10-03 08:00:00', 52.0, 13.0),
            $this->createMedia(12, '2023-10-03 09:30:00', 52.0005, 13.0005),
            $this->createMedia(13, '2023-10-03 11:00:00', 52.0010, 13.0010),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/holiday-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 2048,
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
