<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\OneYearAgoClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class OneYearAgoClusterStrategyTest extends TestCase
{
    #[Test]
    public function gathersItemsWithinWindowAroundLastYear(): void
    {
        $strategy = new OneYearAgoClusterStrategy(
            timezone: 'Europe/Berlin',
            windowDays: 2,
            minItemsTotal: 4,
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $anchorBase = $now->sub(new DateInterval('P1Y'));

        $mediaItems = [
            $this->createMedia(1, $anchorBase->modify('-1 day')),
            $this->createMedia(2, $anchorBase),
            $this->createMedia(3, $anchorBase->modify('+1 day')),
            $this->createMedia(4, $anchorBase->modify('+2 days')),
            $this->createMedia(5, $anchorBase->modify('+5 days')),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('one_year_ago', $cluster->getAlgorithm());
        self::assertSame([1, 2, 3, 4], $cluster->getMembers());
        self::assertArrayHasKey('time_range', $cluster->getParams());
    }

    #[Test]
    public function enforcesMinimumItemCount(): void
    {
        $strategy = new OneYearAgoClusterStrategy(
            timezone: 'Europe/Berlin',
            windowDays: 1,
            minItemsTotal: 3,
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $anchorBase = $now->sub(new DateInterval('P1Y'));

        $mediaItems = [
            $this->createMedia(11, $anchorBase),
            $this->createMedia(12, $anchorBase->modify('+1 day')),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/one-year-ago-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt->setTimezone(new DateTimeZone('UTC')));

        return $media;
    }

}
