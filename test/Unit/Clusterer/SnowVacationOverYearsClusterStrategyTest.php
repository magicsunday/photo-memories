<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\SnowVacationOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SnowVacationOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesBestSnowTripsAcrossYears(): void
    {
        $strategy = new SnowVacationOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 2,
            minNights: 2,
            maxNights: 5,
            minYears: 3,
            minItemsTotal: 12,
        );

        $items = [];
        foreach ([2020, 2021, 2022] as $year) {
            $start = new DateTimeImmutable(sprintf('%d-01-10 09:00:00', $year), new DateTimeZone('UTC'));

            for ($dayOffset = 0; $dayOffset < 3; ++$dayOffset) {
                $day = $start->add(new DateInterval('P' . $dayOffset . 'D'));

                for ($i = 0; $i < 2; ++$i) {
                    $items[] = $this->createMedia(
                        ($year * 100) + ($dayOffset * 10) + $i,
                        $day->add(new DateInterval('PT' . ($i * 3600) . 'S')),
                        'ski-trip-' . $year . '-' . $dayOffset . '-' . $i . '.jpg',
                        46.8 + ($dayOffset * 0.01),
                        11.2 + ($i * 0.01),
                    );
                }
            }

            // Add a non-winter day that should be ignored.
            $items[] = $this->createMedia(
                ($year * 1000) + 99,
                new DateTimeImmutable(sprintf('%d-06-05 12:00:00', $year), new DateTimeZone('UTC')),
                'summer-hike-' . $year . '.jpg',
                45.0,
                10.0,
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('snow_vacation_over_years', $cluster->getAlgorithm());
        self::assertSame([2020, 2021, 2022], $cluster->getParams()['years']);
        self::assertCount(18, $cluster->getMembers());

        $expectedRange = [
            'from' => (new DateTimeImmutable('2020-01-10 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2022-01-12 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertIsArray($centroid);
        self::assertArrayHasKey('lat', $centroid);
        self::assertArrayHasKey('lon', $centroid);
    }

    #[Test]
    public function rejectsWhenMinimumsNotMet(): void
    {
        $strategy = new SnowVacationOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 3,
            minNights: 2,
            maxNights: 5,
            minYears: 3,
            minItemsTotal: 18,
        );

        $items = [];
        foreach ([2020, 2021] as $year) {
            $start = new DateTimeImmutable(sprintf('%d-02-03 08:00:00', $year), new DateTimeZone('UTC'));

            for ($dayOffset = 0; $dayOffset < 3; ++$dayOffset) {
                $day = $start->add(new DateInterval('P' . $dayOffset . 'D'));

                for ($i = 0; $i < 2; ++$i) {
                    // Only two items per day, below the configured threshold.
                    $items[] = $this->createMedia(
                        ($year * 200) + ($dayOffset * 10) + $i,
                        $day->add(new DateInterval('PT' . ($i * 1800) . 'S')),
                        'snowboard-' . $year . '-' . $dayOffset . '-' . $i . '.jpg',
                        47.1,
                        10.9,
                    );
                }
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(
        int $id,
        DateTimeImmutable $takenAt,
        string $path,
        float $lat,
        float $lon,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: $path,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            size: 4096,
        );
    }
}
