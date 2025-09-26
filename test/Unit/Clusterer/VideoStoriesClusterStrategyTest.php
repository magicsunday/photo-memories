<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\VideoStoriesClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VideoStoriesClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersVideosByLocalDay(): void
    {
        $strategy = new VideoStoriesClusterStrategy(
            timezone: 'Europe/Berlin',
            minItems: 2,
        );

        $base = new DateTimeImmutable('2024-03-15 08:00:00');
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
            $this->createPhoto(3400, new DateTimeImmutable('2024-03-16 08:00:00')),
            $this->createPhoto(3401, new DateTimeImmutable('2024-03-16 09:00:00')),
        ];

        self::assertSame([], $strategy->cluster($items));
    }

    private function createVideo(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/video-' . $id . '.mp4',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 4096,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setMime('video/mp4');
        $media->setGpsLat(48.1);
        $media->setGpsLon(11.6);

        return $media;
    }

    private function createPhoto(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/photo-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setMime('image/jpeg');

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
