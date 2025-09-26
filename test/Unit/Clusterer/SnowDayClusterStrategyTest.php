<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\SnowDayClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SnowDayClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersWinterSessionsWithSnowKeywords(): void
    {
        $strategy = new SnowDayClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 5400,
            minItemsPerRun: 6,
        );

        $start = new DateTimeImmutable('2024-01-12 09:00:00', new DateTimeZone('UTC'));
        $media = [];
        $keywords = ['snow', 'ski', 'piste', 'snowboard', 'eiszapfen', 'schnee'];
        foreach ($keywords as $index => $keyword) {
            $media[] = $this->createMedia(
                1000 + $index,
                $start->add(new DateInterval('PT' . ($index * 25) . 'M')),
                47.0 + ($index * 0.001),
                11.0 + ($index * 0.001),
                __DIR__ . "/fixtures/{$keyword}-moment.jpg",
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('snow_day', $cluster->getAlgorithm());
        self::assertSame(range(1000, 1005), $cluster->getMembers());
    }

    #[Test]
    public function ignoresOutOfSeasonOrNonSnowSessions(): void
    {
        $strategy = new SnowDayClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 5400,
            minItemsPerRun: 4,
        );

        $start = new DateTimeImmutable('2024-04-01 09:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 4; $i++) {
            $media[] = $this->createMedia(
                1100 + $i,
                $start->add(new DateInterval('PT' . ($i * 30) . 'M')),
                47.5 + ($i * 0.001),
                11.5 + ($i * 0.001),
                __DIR__ . "/fixtures/mountain-hike-$i.jpg",
            );
        }

        self::assertSame([], $strategy->cluster($media));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon, string $path): Media
    {
        $media = new Media(
            path: $path,
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
