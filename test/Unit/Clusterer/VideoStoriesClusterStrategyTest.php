<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\VideoStoriesClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class VideoStoriesClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersVideosByLocalDay(): void
    {
        $strategy = new VideoStoriesClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
        );

        $base = new DateTimeImmutable('2024-03-15 08:00:00', new DateTimeZone('UTC'));
        $videos = [];
        for ($i = 0; $i < 3; $i++) {
            $videos[] = $this->createVideo(3300 + $i, $base->add(new DateInterval('PT' . ($i * 1800) . 'S')));
        }

        $clusters = $strategy->cluster($videos);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('video_stories', $cluster->getAlgorithm());
        self::assertSame([3300, 3301, 3302], $cluster->getMembers());
    }

    #[Test]
    public function ignoresNonVideoMedia(): void
    {
        $strategy = new VideoStoriesClusterStrategy();

        $items = [
            $this->createPhoto(3400, new DateTimeImmutable('2024-03-16 08:00:00', new DateTimeZone('UTC'))),
            $this->createPhoto(3401, new DateTimeImmutable('2024-03-16 09:00:00', new DateTimeZone('UTC'))),
        ];

        self::assertSame([], $strategy->cluster($items));
    }

    private function createVideo(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: "video-{$id}.mp4",
            takenAt: $takenAt,
            lat: 48.1,
            lon: 11.6,
            size: 4096,
            configure: static function (Media $media): void {
                $media->setMime('video/mp4');
            },
        );
    }

    private function createPhoto(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: "photo-{$id}.jpg",
            takenAt: $takenAt,
            configure: static function (Media $media): void {
                $media->setMime('image/jpeg');
            },
        );
    }

}
