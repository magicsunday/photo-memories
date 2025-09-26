<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\GoldenHourClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GoldenHourClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersGoldenHourSequence(): void
    {
        $strategy = new GoldenHourClusterStrategy(
            timezone: 'Europe/Berlin',
            morningHours: [6, 7, 8],
            eveningHours: [18, 19, 20],
            sessionGapSeconds: 1200,
            minItemsPerRun: 5,
        );

        $base = new DateTimeImmutable('2024-08-10 18:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                2700 + $i,
                $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('golden_hour', $clusters[0]->getAlgorithm());
        self::assertSame(range(2700, 2704), $clusters[0]->getMembers());
    }

    #[Test]
    public function ignoresPhotosOutsideGoldenHours(): void
    {
        $strategy = new GoldenHourClusterStrategy();

        $base = new DateTimeImmutable('2024-08-10 13:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                2800 + $i,
                $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/golden-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat(48.5);
        $media->setGpsLon(9.0);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
