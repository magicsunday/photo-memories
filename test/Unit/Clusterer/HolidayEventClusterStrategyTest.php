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
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class HolidayEventClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsItemsByHolidayPerYear(): void
    {
        $strategy = new HolidayEventClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minItemsPerHoliday: 3,
        );

        $berlinChristmas = $this->makeLocation(
            providerPlaceId: 'berlin-xmas',
            displayName: 'Weihnachtsmarkt Berlin',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
            configure: static function (Location $location): void {
                $location->setState('Berlin');
            },
        );

        $munichChristmas = $this->makeLocation(
            providerPlaceId: 'munich-xmas',
            displayName: 'Weihnachtsmarkt München',
            lat: 48.1351,
            lon: 11.5820,
            city: 'Munich',
            country: 'Germany',
            configure: static function (Location $location): void {
                $location->setState('Bavaria');
            },
        );

        $mediaItems = [
            $this->createMedia(1, '2023-12-25 09:00:00', 52.5, 13.4, $berlinChristmas),
            $this->createMedia(2, '2023-12-25 10:00:00', 52.5005, 13.401, $berlinChristmas),
            $this->createMedia(3, '2023-12-25 12:00:00', 52.499, 13.402, $berlinChristmas),
            $this->createMedia(4, '2024-12-25 09:30:00', 48.1, 11.6, $munichChristmas),
            $this->createMedia(5, '2024-12-25 10:30:00', 48.1005, 11.6005, $munichChristmas),
            $this->createMedia(6, '2024-12-25 11:00:00', 48.1009, 11.6010, $munichChristmas),
            $this->createMedia(7, '2023-05-01 09:15:00', 49.0, 12.0),
        ];

        $mediaItems[0]->setSceneTags([
            ['label' => 'Weihnachten', 'score' => 0.95],
        ]);
        $mediaItems[0]->setKeywords(['Weihnachten']);

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(2, $clusters);

        $first = $clusters[0];
        self::assertSame('holiday_event', $first->getAlgorithm());
        self::assertSame(2023, $first->getParams()['year']);
        self::assertSame('1. Weihnachtstag', $first->getParams()['holiday_name']);
        self::assertSame([1, 2, 3], $first->getMembers());
        self::assertSame([
            ['label' => 'Weihnachten', 'score' => 0.95],
        ], $first->getParams()['scene_tags']);
        self::assertSame(['Weihnachten'], $first->getParams()['keywords']);
        self::assertArrayHasKey('place', $first->getParams());
        self::assertNotSame('', $first->getParams()['place']);
        self::assertSame('Berlin', $first->getParams()['place_city']);
        self::assertSame('Germany', $first->getParams()['place_country']);

        $second = $clusters[1];
        self::assertSame(2024, $second->getParams()['year']);
        self::assertSame([4, 5, 6], $second->getMembers());
    }

    #[Test]
    public function filtersGroupsBelowMinimumCount(): void
    {
        $strategy = new HolidayEventClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minItemsPerHoliday: 4,
        );

        $mediaItems = [
            $this->createMedia(11, '2023-10-03 08:00:00', 52.0, 13.0),
            $this->createMedia(12, '2023-10-03 09:30:00', 52.0005, 13.0005),
            $this->createMedia(13, '2023-10-03 11:00:00', 52.0010, 13.0010),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function featureDrivenHolidayGroupingMatchesFallback(): void
    {
        $strategy = new HolidayEventClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minItemsPerHoliday: 2,
        );

        $items = [
            $this->createMedia(201, '2024-03-29 09:00:00', 50.0, 8.0),
            $this->createMedia(202, '2024-03-29 11:00:00', 50.0005, 8.0005),
        ];

        $fallbackClusters = $this->normaliseClusters($strategy->cluster($items));

        foreach ($items as $media) {
            $media->setFeatures([
                'calendar' => [
                    'isHoliday' => true,
                    'holidayId' => 'de-goodfriday-2024',
                ],
            ]);
        }

        $featureClusters = $this->normaliseClusters($strategy->cluster($items));

        self::assertSame($fallbackClusters, $featureClusters);
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon, ?Location $location = null): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('holiday-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            size: 2048,
            location: $location,
        );
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return list<array{algorithm: string, params: array, centroid: array, members: list<int>}>
     */
    private function normaliseClusters(array $clusters): array
    {
        return array_map(
            static fn (ClusterDraft $cluster): array => [
                'algorithm' => $cluster->getAlgorithm(),
                'params'    => $cluster->getParams(),
                'centroid'  => $cluster->getCentroid(),
                'members'   => $cluster->getMembers(),
            ],
            $clusters,
        );
    }
}
