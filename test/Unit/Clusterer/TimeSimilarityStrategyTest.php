<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\TimeSimilarityStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class TimeSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function clustersMediaWithinGapAndLocalityBoundaries(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = new TimeSimilarityStrategy(
            locHelper: $helper,
            maxGapSeconds: 1800,
            minItemsPerBucket: 3,
        );

        $berlin = $this->makeLocation(
            providerPlaceId: 'berlin-city',
            displayName: 'Berlin',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
        );
        $munich = $this->makeLocation(
            providerPlaceId: 'munich-city',
            displayName: 'Munich',
            lat: 48.1371,
            lon: 11.5753,
            city: 'Munich',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(1001, '2023-07-01 09:00:00', 52.5201, 13.4049, $berlin),
            $this->createMedia(1002, '2023-07-01 09:20:00', 52.5202, 13.4050, $berlin),
            $this->createMedia(1003, '2023-07-01 09:40:00', 52.5203, 13.4051, $berlin),
            // Gap larger than max gap -> split bucket
            $this->createMedia(1004, '2023-07-01 12:30:00', 52.5204, 13.4052, $berlin),
            // Same day but different locality should not join previous bucket
            $this->createMedia(1005, '2023-07-01 12:40:00', 48.1372, 11.5754, $munich),
            $this->createMedia(1006, '2023-07-01 12:55:00', 48.1373, 11.5755, $munich),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('time_similarity', $cluster->getAlgorithm());
        self::assertSame([1001, 1002, 1003], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame('Berlin', $params['place']);
        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-07-01 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-07-01 09:40:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.5202, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(13.4050, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function returnsEmptyWhenNoBucketMeetsMinimumItems(): void
    {
        $strategy = new TimeSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            maxGapSeconds: 900,
            minItemsPerBucket: 4,
        );

        $location = $this->makeLocation(
            providerPlaceId: 'hamburg-city',
            displayName: 'Hamburg',
            lat: 53.5511,
            lon: 9.9937,
            city: 'Hamburg',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(1101, '2023-08-10 08:00:00', 53.5510, 9.9936, $location),
            $this->createMedia(1102, '2023-08-10 08:12:00', 53.5511, 9.9937, $location),
            $this->createMedia(1103, '2023-08-10 08:25:00', 53.5512, 9.9938, $location),
            // Splits due to time gap
            $this->createMedia(1104, '2023-08-10 10:00:00', 53.5513, 9.9939, $location),
            $this->createMedia(1105, '2023-08-10 10:10:00', 53.5514, 9.9940, $location),
            $this->createMedia(1106, '2023-08-10 10:20:00', 53.5515, 9.9941, $location),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function enrichesClustersWithDominantTags(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = new TimeSimilarityStrategy(
            locHelper: $helper,
            maxGapSeconds: 3600,
            minItemsPerBucket: 2,
        );

        $location = $this->makeLocation(
            providerPlaceId: 'london-city',
            displayName: 'London',
            lat: 51.5074,
            lon: -0.1278,
            city: 'London',
            country: 'United Kingdom',
        );

        $mediaItems = [
            $this->makeMediaFixture(
                id: 2001,
                filename: 'time-tags-2001.jpg',
                takenAt: '2023-06-01 18:00:00',
                lat: 51.5075,
                lon: -0.1277,
                location: $location,
                configure: static function (Media $media): void {
                    $media->setSceneTags([
                        ['label' => 'Beach', 'score' => 0.92],
                        ['label' => 'Sunset', 'score' => 0.55],
                    ]);
                    $media->setKeywords(['Vacation', 'Beach Day']);
                }
            ),
            $this->makeMediaFixture(
                id: 2002,
                filename: 'time-tags-2002.jpg',
                takenAt: '2023-06-01 18:20:00',
                lat: 51.5076,
                lon: -0.1276,
                location: $location,
                configure: static function (Media $media): void {
                    $media->setSceneTags([
                        ['label' => 'Beach', 'score' => 0.82],
                        ['label' => 'Sunset', 'score' => 0.61],
                    ]);
                    $media->setKeywords(['Vacation', 'Sunset']);
                }
            ),
            $this->makeMediaFixture(
                id: 2003,
                filename: 'time-tags-2003.jpg',
                takenAt: '2023-06-01 18:45:00',
                lat: 51.5077,
                lon: -0.1275,
                location: $location,
                configure: static function (Media $media): void {
                    $media->setSceneTags([
                        ['label' => 'Forest', 'score' => 0.75],
                    ]);
                    $media->setKeywords(['Beach Day']);
                }
            ),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $params = $clusters[0]->getParams();

        self::assertArrayHasKey('scene_tags', $params);
        /** @var list<array{label: string, score: float}> $sceneTags */
        $sceneTags = $params['scene_tags'];
        self::assertSame('Beach', $sceneTags[0]['label']);
        self::assertEqualsWithDelta(0.92, $sceneTags[0]['score'], 0.00001);
        self::assertSame('Forest', $sceneTags[1]['label']);
        self::assertSame('Sunset', $sceneTags[2]['label']);

        self::assertArrayHasKey('keywords', $params);
        self::assertSame(['Beach Day', 'Vacation', 'Sunset'], $params['keywords']);
    }

    private function createMedia(
        int $id,
        string $takenAt,
        float $lat,
        float $lon,
        Location $location,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('time-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
        );
    }
}
