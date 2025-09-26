<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ZooAquariumClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZooAquariumClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersDaytimeZooVisits(): void
    {
        $strategy = new ZooAquariumClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 1800,
            radiusMeters: 350.0,
            minItemsPerRun: 5,
            minHour: 9,
            maxHour: 19,
        );

        $start = new DateTimeImmutable('2023-08-12 09:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; $i++) {
            $media[] = $this->createMedia(
                1000 + $i,
                $start->modify('+' . ($i * 20) . ' minutes'),
                "tierpark-{$i}.jpg",
                51.0 + $i * 0.0002,
                7.0 + $i * 0.0002,
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        self::assertSame('zoo_aquarium', $clusters[0]->getAlgorithm());
        self::assertSame(range(1000, 1004), $clusters[0]->getMembers());
    }

    #[Test]
    public function rejectsSessionsOutsideOpeningHours(): void
    {
        $strategy = new ZooAquariumClusterStrategy();

        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                1100 + $i,
                new DateTimeImmutable('2023-08-12 22:00:00', new DateTimeZone('UTC')),
                "tierpark-{$i}.jpg",
                51.0,
                7.0,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $filename, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/' . $filename,
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
