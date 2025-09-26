<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\KeywordBestDayOverYearsStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class KeywordBestDayOverYearsStrategyTest extends TestCase
{
    #[Test]
    public function picksStrongestKeywordDayPerYear(): void
    {
        $strategy = $this->createStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 4,
            minYears: 2,
            minItemsTotal: 10,
            keywords: ['museum'],
        );

        $items = [];

        // 2021 has two keyword days; only the six-item day should be kept.
        $day2021Primary = new DateTimeImmutable('2021-04-15 10:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->createMedia(
                id: 202100 + $i,
                takenAt: $day2021Primary->add(new DateInterval('PT' . ($i * 600) . 'S')),
                pathSuffix: sprintf('museum-2021-primary-%d.jpg', $i),
                lat: 52.5 + $i * 0.01,
                lon: 13.4 + $i * 0.01,
            );
        }

        $day2021Secondary = new DateTimeImmutable('2021-05-20 12:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                id: 202110 + $i,
                takenAt: $day2021Secondary->add(new DateInterval('PT' . ($i * 300) . 'S')),
                pathSuffix: sprintf('museum-2021-secondary-%d.jpg', $i),
                lat: 52.0,
                lon: 13.0,
            );
        }

        // 2022 provides another eligible day.
        $day2022 = new DateTimeImmutable('2022-03-08 09:30:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 4; $i++) {
            $items[] = $this->createMedia(
                id: 202200 + $i,
                takenAt: $day2022->add(new DateInterval('PT' . ($i * 420) . 'S')),
                pathSuffix: sprintf('museum-2022-%d.jpg', $i),
                lat: 53.0,
                lon: 13.2 + $i * 0.01,
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('keyword_best_day_over_years_test', $cluster->getAlgorithm());
        self::assertSame([2021, 2022], $cluster->getParams()['years']);
        self::assertCount(10, $cluster->getMembers());
    }

    #[Test]
    public function enforcesThresholdsBeforeBuildingCluster(): void
    {
        $strategy = $this->createStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 3,
            minYears: 2,
            minItemsTotal: 6,
            keywords: ['museum'],
        );

        $items = [];

        $eligibleDay = new DateTimeImmutable('2020-02-10 13:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->createMedia(
                id: 202000 + $i,
                takenAt: $eligibleDay->add(new DateInterval('PT' . ($i * 900) . 'S')),
                pathSuffix: sprintf('museum-2020-%d.jpg', $i),
                lat: 48.0,
                lon: 11.0,
            );
        }

        // 2021 does not reach the minItemsPerDay threshold (only two keyword hits).
        $underfilledDay = new DateTimeImmutable('2021-04-02 14:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 2; $i++) {
            $items[] = $this->createMedia(
                id: 202100 + $i,
                takenAt: $underfilledDay->add(new DateInterval('PT' . ($i * 600) . 'S')),
                pathSuffix: sprintf('museum-2021-%d.jpg', $i),
                lat: 48.2,
                lon: 11.2,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function groupsByLocalDayBasedOnTimezone(): void
    {
        $strategy = $this->createStrategy(
            timezone: 'America/Los_Angeles',
            minItemsPerDay: 2,
            minYears: 1,
            minItemsTotal: 2,
            keywords: ['museum'],
        );

        $items = [
            $this->createMedia(
                id: 3001,
                takenAt: new DateTimeImmutable('2022-05-02T06:30:00+00:00'),
                pathSuffix: 'museum-west-coast-1.jpg',
                lat: 34.05,
                lon: -118.25,
            ),
            $this->createMedia(
                id: 3002,
                takenAt: new DateTimeImmutable('2022-05-01T18:45:00+00:00'),
                pathSuffix: 'museum-west-coast-2.jpg',
                lat: 34.06,
                lon: -118.24,
            ),
        ];

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();

        self::assertSame([2022], $params['years']);
        self::assertSame($items[1]->getTakenAt()?->getTimestamp(), $params['time_range']['from']);
        self::assertSame($items[0]->getTakenAt()?->getTimestamp(), $params['time_range']['to']);
    }

    /**
     * @param list<string> $keywords
     */
    private function createStrategy(string $timezone, int $minItemsPerDay, int $minYears, int $minItemsTotal, array $keywords): KeywordBestDayOverYearsStrategy
    {
        return new class($timezone, $minItemsPerDay, $minYears, $minItemsTotal, $keywords) extends KeywordBestDayOverYearsStrategy {
            public function name(): string
            {
                return 'keyword_best_day_over_years_test';
            }
        };
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $pathSuffix, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/' . $pathSuffix,
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);

        return $media;
    }

}
