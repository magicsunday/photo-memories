<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\MonthlyHighlightsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MonthlyHighlightsClusterStrategyTest extends TestCase
{
    #[Test]
    public function emitsClusterPerEligibleMonth(): void
    {
        $strategy = new MonthlyHighlightsClusterStrategy(
            timezone: 'UTC',
            minItemsPerMonth: 4,
            minDistinctDays: 3,
        );

        $mediaItems = [
            $this->createMedia(1, '2023-03-01 08:00:00'),
            $this->createMedia(2, '2023-03-02 09:00:00'),
            $this->createMedia(3, '2023-03-02 10:00:00'),
            $this->createMedia(4, '2023-03-05 18:00:00'),
            $this->createMedia(5, '2023-04-01 12:00:00'),
            $this->createMedia(6, '2023-04-02 12:00:00'),
            $this->createMedia(7, '2023-04-03 12:00:00'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('monthly_highlights', $cluster->getAlgorithm());
        self::assertSame(2023, $cluster->getParams()['year']);
        self::assertSame(3, $cluster->getParams()['month']);
        self::assertSame([1, 2, 3, 4], $cluster->getMembers());
    }

    #[Test]
    public function enforcesDistinctDayThreshold(): void
    {
        $strategy = new MonthlyHighlightsClusterStrategy(
            timezone: 'UTC',
            minItemsPerMonth: 4,
            minDistinctDays: 4,
        );

        $mediaItems = [
            $this->createMedia(11, '2023-05-01 08:00:00'),
            $this->createMedia(12, '2023-05-01 09:00:00'),
            $this->createMedia(13, '2023-05-02 09:00:00'),
            $this->createMedia(14, '2023-05-03 09:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/monthly-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')));

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
