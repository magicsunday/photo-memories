<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\PortraitOrientationClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PortraitOrientationClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersPortraitSessions(): void
    {
        $strategy = new PortraitOrientationClusterStrategy(
            minPortraitRatio: 1.2,
            sessionGapSeconds: 900,
            minItems: 4,
        );

        $start = new DateTimeImmutable('2024-04-10 10:00:00');
        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $media = $this->createPortraitMedia(3700 + $i, $start->add(new DateInterval('PT' . ($i * 600) . 'S')));
            $items[] = $media;
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('portrait_orientation', $cluster->getAlgorithm());
        self::assertSame([3700, 3701, 3702, 3703], $cluster->getMembers());
    }

    #[Test]
    public function skipsLandscapePhotos(): void
    {
        $strategy = new PortraitOrientationClusterStrategy();

        $start = new DateTimeImmutable('2024-04-11 10:00:00');
        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $media = $this->createLandscapeMedia(3800 + $i, $start->add(new DateInterval('PT' . ($i * 600) . 'S')));
            $items[] = $media;
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createPortraitMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/portrait-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setWidth(1000);
        $media->setHeight(1500);
        $media->setGpsLat(48.0);
        $media->setGpsLon(11.0);

        return $media;
    }

    private function createLandscapeMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/landscape-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setWidth(1600);
        $media->setHeight(900);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
