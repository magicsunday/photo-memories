<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\CityscapeNightClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CityscapeNightClusterStrategyTest extends TestCase
{
    #[Test]
    public function buildsNighttimeUrbanCluster(): void
    {
        $strategy = new CityscapeNightClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 1800,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $base = new DateTimeImmutable('2023-05-20 20:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; $i++) {
            $media[] = $this->createMedia(
                500 + $i,
                $base->modify('+' . ($i * 20) . ' minutes'),
                "city-skyline-{$i}.jpg",
                48.1351 + $i * 0.0002,
                11.5820 + $i * 0.0002,
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('cityscape_night', $cluster->getAlgorithm());
        self::assertSame([500, 501, 502, 503, 504], $cluster->getMembers());
    }

    #[Test]
    public function ignoresDaytimeScenes(): void
    {
        $strategy = new CityscapeNightClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 1800,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                600 + $i,
                new DateTimeImmutable('2023-05-21 12:00:00', new DateTimeZone('UTC')),
                "city-skyline-{$i}.jpg",
                48.13 + $i * 0.001,
                11.58 + $i * 0.001,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $filename, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/' . $filename,
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 2048,
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
