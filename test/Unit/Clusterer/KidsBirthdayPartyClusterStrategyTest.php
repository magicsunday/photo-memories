<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\KidsBirthdayPartyClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

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
                sprintf('birthday-party-%d-cake.jpg', $i),
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
                sprintf('playdate-%d.jpg', $i),
            );
        }

        self::assertSame([], $strategy->cluster($media));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon, string $filename): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: $filename,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            size: 2048,
        );
    }

}
