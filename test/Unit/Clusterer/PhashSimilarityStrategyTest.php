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
use MagicSunday\Memories\Clusterer\PhashSimilarityStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class PhashSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function clustersNearDuplicateMediaByPhash(): void
    {
        $strategy = new PhashSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            maxHamming: 6,
            minItemsPerBucket: 3,
        );

        $location = $this->makeLocation(
            providerPlaceId: 'berlin-phash',
            displayName: 'Museum Island',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(1501, '2023-09-01 10:00:00', 52.5200, 13.4050, 'abcd0123456789ef', $location),
            $this->createMedia(1502, '2023-09-01 10:01:00', 52.5201, 13.4051, 'abcd0123456789ee', $location),
            $this->createMedia(1503, '2023-09-01 10:02:00', 52.5199, 13.4049, 'abcd0123456788ef', $location),
            $this->createMedia(1504, '2023-09-01 10:03:00', 52.5202, 13.4052, 'abcd0123456689ef', $location),
            // Different prefix -> separate bucket
            $this->createMedia(1601, '2023-09-01 11:00:00', 48.1371, 11.5753, 'ffff999988887777', $location),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('phash_similarity', $cluster->getAlgorithm());
        self::assertSame([1501, 1502, 1503, 1504], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame('Berlin', $params['place']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-09-01 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-09-01 10:03:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52005, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(13.40505, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function returnsEmptyWhenHashesAreTooDissimilar(): void
    {
        $strategy = new PhashSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            maxHamming: 2,
            minItemsPerBucket: 2,
        );

        $location = $this->makeLocation(
            providerPlaceId: 'munich-phash',
            displayName: 'Marienplatz',
            lat: 48.1371,
            lon: 11.5753,
            city: 'Munich',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(1701, '2023-10-05 09:00:00', 48.1371, 11.5753, 'abcd000000000000', $location),
            $this->createMedia(1702, '2023-10-05 09:01:00', 48.1372, 11.5754, 'abcdffffffffffff', $location),
            $this->createMedia(1703, '2023-10-05 09:02:00', 48.1373, 11.5755, 'abcd111111111111', $location),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(
        int $id,
        string $takenAt,
        float $lat,
        float $lon,
        string $phash,
        Location $location,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('phash-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: static function (Media $media) use ($phash): void {
                $media->setPhash($phash);
            },
        );
    }
}
