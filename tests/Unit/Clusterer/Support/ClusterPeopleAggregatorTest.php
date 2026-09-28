<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Support;

use MagicSunday\Memories\Clusterer\Support\ClusterPeopleAggregator;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;

/**
 * @covers \MagicSunday\Memories\Clusterer\Support\ClusterPeopleAggregator
 */
final class ClusterPeopleAggregatorTest extends TestCase
{
    public function testFavouriteHeavyClusterOutscoresNeutralCluster(): void
    {
        $favouriteId = 101;
        $aggregator  = new ClusterPeopleAggregator([$favouriteId]);

        $favouriteMedia = [
            $this->makePersonTaggedMedia(
                id: 1,
                path: '/library/favourite-1.jpg',
                personIds: [$favouriteId],
                configure: static function (Media $media): void {
                    $media->setPersons(['Alice']);
                },
            ),
            $this->makePersonTaggedMedia(
                id: 2,
                path: '/library/favourite-2.jpg',
                personIds: [$favouriteId],
                configure: static function (Media $media): void {
                    $media->setPersons(['Alice']);
                },
            ),
            $this->makeMedia(3, '/library/favourite-3.jpg'),
            $this->makeMedia(4, '/library/favourite-4.jpg'),
        ];

        $neutralMedia = [
            $this->makePersonTaggedMedia(
                id: 5,
                path: '/library/neutral-1.jpg',
                personIds: [201],
                configure: static function (Media $media): void {
                    $media->setPersons(['Eve']);
                },
            ),
            $this->makePersonTaggedMedia(
                id: 6,
                path: '/library/neutral-2.jpg',
                personIds: [201],
                configure: static function (Media $media): void {
                    $media->setPersons(['Eve']);
                },
            ),
            $this->makeMedia(7, '/library/neutral-3.jpg'),
            $this->makeMedia(8, '/library/neutral-4.jpg'),
        ];

        $favouriteParams = $aggregator->buildParams($favouriteMedia);
        $neutralParams   = $aggregator->buildParams($neutralMedia);

        self::assertGreaterThan(
            $neutralParams['people'],
            $favouriteParams['people'],
            'Favourite-heavy clusters should receive a higher people score.',
        );

        self::assertEqualsWithDelta(0.5, $favouriteParams['people_favourite_coverage'], 0.0001);
        self::assertSame(0.0, $neutralParams['people_favourite_coverage']);
        self::assertSame($neutralParams['people_count'], $favouriteParams['people_count']);
        self::assertSame($neutralParams['people_unique'], $favouriteParams['people_unique']);
    }
}
