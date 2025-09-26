<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\NightlifeEventClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NightlifeEventClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersNightSessionsWithCompactGpsSpread(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            timezone: 'Europe/Berlin',
            timeGapSeconds: 3 * 3600,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-15 20:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; $i++) {
            $media[] = $this->createMedia(
                610 + $i,
                $start->add(new DateInterval('PT' . ($i * 45) . 'M')),
                52.5205 + ($i * 0.0002),
                13.4049 + ($i * 0.0002),
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('nightlife_event', $cluster->getAlgorithm());
        self::assertSame(range(610, 614), $cluster->getMembers());

        $timeRange = $cluster->getParams()['time_range'];
        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $timeRange['from']);
        self::assertSame($media[4]->getTakenAt()?->getTimestamp(), $timeRange['to']);
    }

    #[Test]
    public function rejectsRunsExceedingSpatialRadius(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            timezone: 'Europe/Berlin',
            timeGapSeconds: 3 * 3600,
            radiusMeters: 50.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-16 22:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; $i++) {
            $media[] = $this->createMedia(
                710 + $i,
                $start->add(new DateInterval('PT' . ($i * 30) . 'M')),
                52.50 + ($i * 0.01),
                13.40 + ($i * 0.01),
            );
        }

        self::assertSame([], $strategy->cluster($media));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/nightlife-$id.jpg",
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
