<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\MorningCoffeeClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MorningCoffeeClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersCompactMorningCafeSession(): void
    {
        $strategy = new MorningCoffeeClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 900,
            radiusMeters: 150.0,
            minItemsPerRun: 3,
            minHour: 7,
            maxHour: 10,
        );

        $base = new DateTimeImmutable('2023-06-10 07:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 3; $i++) {
            $media[] = $this->createMedia(
                1500 + $i,
                $base->modify('+' . ($i * 10) . ' minutes'),
                "coffee-bar-{$i}.jpg",
                48.208 + $i * 0.0005,
                16.372 + $i * 0.0005,
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        self::assertSame('morning_coffee', $clusters[0]->getAlgorithm());
        self::assertSame([1500, 1501, 1502], $clusters[0]->getMembers());
    }

    #[Test]
    public function rejectsEventsOutsideMorningWindow(): void
    {
        $strategy = new MorningCoffeeClusterStrategy();

        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->createMedia(
                1600 + $i,
                new DateTimeImmutable('2023-06-10 13:00:00', new DateTimeZone('UTC')),
                "coffee-bar-{$i}.jpg",
                48.21,
                16.37,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $filename, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/' . $filename,
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 256,
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
