<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\HikeAdventureClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HikeAdventureClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersHikeWhenDistanceMet(): void
    {
        $strategy = new HikeAdventureClusterStrategy(
            sessionGapSeconds: 1800,
            minDistanceKm: 5.0,
            minItemsPerRun: 6,
            minItemsPerRunNoGps: 10,
        );

        $start = new DateTimeImmutable('2023-09-10 08:00:00');
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->createMedia(
                3100 + $i,
                $start->add(new DateInterval('PT' . ($i * 900) . 'S')),
                47.0 + $i * 0.05,
                10.0 + $i * 0.05,
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('hike_adventure', $clusters[0]->getAlgorithm());
        self::assertSame(range(3100, 3105), $clusters[0]->getMembers());
    }

    #[Test]
    public function requiresSufficientGpsDistance(): void
    {
        $strategy = new HikeAdventureClusterStrategy(
            sessionGapSeconds: 1800,
            minDistanceKm: 8.0,
            minItemsPerRun: 6,
            minItemsPerRunNoGps: 10,
        );

        $start = new DateTimeImmutable('2023-09-11 08:00:00');
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->createMedia(
                3200 + $i,
                $start->add(new DateInterval('PT' . ($i * 900) . 'S')),
                47.0 + $i * 0.005,
                10.0 + $i * 0.005,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/wanderung-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
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
