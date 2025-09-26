<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\KidsBirthdayPartyClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KidsBirthdayPartyClusterStrategyTest extends TestCase
{
    #[Test]
    public function detectsKeywordDrivenBirthdaySessionsWithinTimeWindow(): void
    {
        $strategy = new KidsBirthdayPartyClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 3 * 3600,
            radiusMeters: 300.0,
            minItemsPerRun: 6,
            minHour: 9,
            maxHour: 21,
        );

        $start = new DateTimeImmutable('2024-05-04 10:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 6; $i++) {
            $media[] = $this->createMedia(
                400 + $i,
                $start->add(new DateInterval('PT' . ($i * 20) . 'M')),
                48.137 + ($i * 0.0003),
                11.575 + ($i * 0.0003),
                __DIR__ . "/fixtures/birthday-party-$i-cake.jpg",
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('kids_birthday_party', $cluster->getAlgorithm());
        self::assertSame(range(400, 405), $cluster->getMembers());

        $timeRange = $cluster->getParams()['time_range'];
        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $timeRange['from']);
        self::assertSame($media[5]->getTakenAt()?->getTimestamp(), $timeRange['to']);
    }

    #[Test]
    public function rejectsSessionsMissingBirthdaySignals(): void
    {
        $strategy = new KidsBirthdayPartyClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 3 * 3600,
            radiusMeters: 300.0,
            minItemsPerRun: 5,
            minHour: 10,
            maxHour: 20,
        );

        $start = new DateTimeImmutable('2024-05-04 11:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; $i++) {
            $media[] = $this->createMedia(
                500 + $i,
                $start->add(new DateInterval('PT' . ($i * 15) . 'M')),
                48.20 + ($i * 0.0004),
                11.60 + ($i * 0.0004),
                __DIR__ . "/fixtures/playdate-$i.jpg",
            );
        }

        self::assertSame([], $strategy->cluster($media));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon, string $path): Media
    {
        $media = new Media(
            path: $path,
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
