<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\PetMomentsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PetMomentsClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersPetPhotosWithinSession(): void
    {
        $strategy = new PetMomentsClusterStrategy(
            sessionGapSeconds: 1200,
            minItemsPerRun: 6,
        );

        $start = new DateTimeImmutable('2024-01-20 15:00:00');
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->createMedia(
                3500 + $i,
                $start->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('pet_moments', $clusters[0]->getAlgorithm());
        self::assertSame(range(3500, 3505), $clusters[0]->getMembers());
    }

    #[Test]
    public function enforcesMinimumItemCount(): void
    {
        $strategy = new PetMomentsClusterStrategy();

        $start = new DateTimeImmutable('2024-01-21 15:00:00');
        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $items[] = $this->createMedia(
                3600 + $i,
                $start->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/dog-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat(52.5);
        $media->setGpsLon(13.4);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
