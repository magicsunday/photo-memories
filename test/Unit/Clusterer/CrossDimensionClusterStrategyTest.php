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
use MagicSunday\Memories\Clusterer\CrossDimensionClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CrossDimensionClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersMediaWhenTimeAndDistanceConstraintsAreSatisfied(): void
    {
        $strategy = new CrossDimensionClusterStrategy(
            timeGapSeconds: 900,
            radiusMeters: 150.0,
            minItemsPerRun: 4,
        );

        $mediaItems = [
            $this->createMedia(1201, '2023-08-15 10:00:00', 40.7128, -74.0060),
            $this->createMedia(1202, '2023-08-15 10:05:00', 40.7129, -74.0059),
            $this->createMedia(1203, '2023-08-15 10:08:00', 40.7127, -74.0061),
            $this->createMedia(1204, '2023-08-15 10:12:00', 40.7129, -74.0060),
            // Separate run due to large time gap
            $this->createMedia(1205, '2023-08-15 13:00:00', 40.7300, -74.0000),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('cross_dimension', $cluster->getAlgorithm());
        self::assertSame([1201, 1202, 1203, 1204], $cluster->getMembers());

        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-08-15 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-08-15 10:12:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $cluster->getParams()['time_range']);
        self::assertArrayHasKey('quality_avg', $cluster->getParams());

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(40.712825, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(-74.0060, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function skipsRunsThatExceedRadiusConstraint(): void
    {
        $strategy = new CrossDimensionClusterStrategy(
            timeGapSeconds: 900,
            radiusMeters: 80.0,
            minItemsPerRun: 4,
        );

        $mediaItems = [
            $this->createMedia(1301, '2023-09-10 15:00:00', 34.0522, -118.2437),
            $this->createMedia(1302, '2023-09-10 15:05:00', 34.0523, -118.2438),
            $this->createMedia(1303, '2023-09-10 15:08:00', 34.0524, -118.2439),
            // Farther away -> centroid distance > radiusMeters
            $this->createMedia(1304, '2023-09-10 15:10:00', 34.0600, -118.2500),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('cross-dimension-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            configure: static function (Media $media): void {
                $media->setWidth(4000);
                $media->setHeight(3000);
                $media->setSharpness(0.75);
                $media->setIso(100);
                $media->setBrightness(0.6);
                $media->setContrast(0.7);
                $media->setEntropy(0.8);
                $media->setColorfulness(0.9);
            },
        );
    }
}
