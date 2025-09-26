<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\SeasonClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SeasonClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsItemsBySeasonPerYear(): void
    {
        $strategy = new SeasonClusterStrategy(minItemsPerSeason: 4);

        $mediaItems = [
            $this->createMedia(1, '2023-12-15 09:00:00'),
            $this->createMedia(2, '2024-01-05 11:00:00'),
            $this->createMedia(3, '2024-02-10 14:00:00'),
            $this->createMedia(4, '2024-02-15 08:30:00'),
            $this->createMedia(5, '2024-07-01 12:00:00'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('season', $cluster->getAlgorithm());
        self::assertSame('Winter', $cluster->getParams()['label']);
        self::assertSame(2024, $cluster->getParams()['year']);
        self::assertSame([1, 2, 3, 4], $cluster->getMembers());
    }

    #[Test]
    public function skipsGroupsBelowMinimum(): void
    {
        $strategy = new SeasonClusterStrategy(minItemsPerSeason: 3);

        $mediaItems = [
            $this->createMedia(11, '2024-06-01 10:00:00'),
            $this->createMedia(12, '2024-06-05 11:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/season-{$id}.jpg",
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
