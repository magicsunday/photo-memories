<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\HolidayEventClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HolidayEventClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsItemsByHolidayPerYear(): void
    {
        $strategy = new HolidayEventClusterStrategy(minItemsPerHoliday: 3);

        $mediaItems = [
            $this->createMedia(1, '2023-12-25 09:00:00', 52.5, 13.4, ['isHoliday' => true, 'holidayId' => 'de-xmas1-2023']),
            $this->createMedia(2, '2023-12-25 10:00:00', 52.5005, 13.401, ['isHoliday' => true, 'holidayId' => 'de-xmas1-2023']),
            $this->createMedia(3, '2023-12-25 12:00:00', 52.499, 13.402, ['isHoliday' => true, 'holidayId' => 'de-xmas1-2023']),
            $this->createMedia(4, '2024-12-25 09:30:00', 48.1, 11.6, ['isHoliday' => true, 'holidayId' => 'de-xmas1-2024']),
            $this->createMedia(5, '2024-12-25 10:30:00', 48.1005, 11.6005, ['isHoliday' => true, 'holidayId' => 'de-xmas1-2024']),
            $this->createMedia(6, '2024-12-25 11:00:00', 48.1009, 11.6010, ['isHoliday' => true, 'holidayId' => 'de-xmas1-2024']),
            $this->createMedia(7, '2023-05-01 09:15:00', 49.0, 12.0, ['isHoliday' => true, 'holidayId' => 'de-labour-2023']),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(2, $clusters);

        $first = $clusters[0];
        self::assertSame('holiday_event', $first->getAlgorithm());
        self::assertSame(2023, $first->getParams()['year']);
        self::assertSame('1. Weihnachtstag', $first->getParams()['holiday_name']);
        self::assertSame([1, 2, 3], $first->getMembers());

        $second = $clusters[1];
        self::assertSame(2024, $second->getParams()['year']);
        self::assertSame([4, 5, 6], $second->getMembers());
    }

    #[Test]
    public function filtersGroupsBelowMinimumCount(): void
    {
        $strategy = new HolidayEventClusterStrategy(minItemsPerHoliday: 4);

        $mediaItems = [
            $this->createMedia(11, '2023-10-03 08:00:00', 52.0, 13.0, ['isHoliday' => true, 'holidayId' => 'de-unity-2023']),
            $this->createMedia(12, '2023-10-03 09:30:00', 52.0005, 13.0005, ['isHoliday' => true, 'holidayId' => 'de-unity-2023']),
            $this->createMedia(13, '2023-10-03 11:00:00', 52.0010, 13.0010, ['isHoliday' => true, 'holidayId' => 'de-unity-2023']),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function featureBasedAndFallbackHolidayDetectionMatch(): void
    {
        $strategy = new HolidayEventClusterStrategy(minItemsPerHoliday: 3);

        $dataset = [
            ['id' => 21, 'takenAt' => '2023-12-25 09:00:00', 'lat' => 52.5, 'lon' => 13.4, 'holidayId' => 'de-xmas1-2023'],
            ['id' => 22, 'takenAt' => '2023-12-25 10:00:00', 'lat' => 52.5005, 'lon' => 13.401, 'holidayId' => 'de-xmas1-2023'],
            ['id' => 23, 'takenAt' => '2023-12-25 12:00:00', 'lat' => 52.499, 'lon' => 13.402, 'holidayId' => 'de-xmas1-2023'],
            ['id' => 24, 'takenAt' => '2024-05-01 08:30:00', 'lat' => 50.1, 'lon' => 8.6, 'holidayId' => 'de-labour-2024'],
            ['id' => 25, 'takenAt' => '2024-05-01 09:15:00', 'lat' => 50.1005, 'lon' => 8.6005, 'holidayId' => 'de-labour-2024'],
            ['id' => 26, 'takenAt' => '2024-05-01 11:45:00', 'lat' => 50.101, 'lon' => 8.601, 'holidayId' => 'de-labour-2024'],
        ];

        $withFeatures = [];
        $fallbackOnly = [];

        foreach ($dataset as $row) {
            $withFeatures[] = $this->createMedia(
                $row['id'],
                $row['takenAt'],
                $row['lat'],
                $row['lon'],
                ['isHoliday' => true, 'holidayId' => $row['holidayId']],
            );

            $fallbackOnly[] = $this->createMedia(
                $row['id'],
                $row['takenAt'],
                $row['lat'],
                $row['lon'],
            );
        }

        $clustersWith = $strategy->cluster($withFeatures);
        $clustersWithout = $strategy->cluster($fallbackOnly);

        self::assertSame(
            $this->normaliseClusters($clustersWithout),
            $this->normaliseClusters($clustersWith),
        );
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return list<array{algorithm: string, params: array, members: list<int>}> 
     */
    private function normaliseClusters(array $clusters): array
    {
        return array_map(
            static fn (ClusterDraft $cluster): array => [
                'algorithm' => $cluster->getAlgorithm(),
                'params'    => $cluster->getParams(),
                'members'   => $cluster->getMembers(),
            ],
            $clusters,
        );
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon, ?array $features = null): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('holiday-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            size: 2048,
            configure: $features !== null
                ? static function (Media $media) use ($features): void {
                    $media->setFeatures($features);
                }
                : null,
        );
    }
}
