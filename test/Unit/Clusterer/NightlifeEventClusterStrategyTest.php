<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\NightlifeEventClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class NightlifeEventClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersNightSessionsWhenFeatureIndicatesNight(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            timeGapSeconds: 3 * 3600,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-15 20:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                610 + $i,
                $start->add(new DateInterval('PT' . ($i * 45) . 'M')),
                52.5205 + ($i * 0.0002),
                13.4049 + ($i * 0.0002),
                static function (Media $media): void {
                    $media->setFeatures([
                        'calendar' => ['daypart' => 'night'],
                    ]);
                },
            );
        }

        $clusters = $strategy->draft($media, Context::fromScope($media));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('nightlife_event', $cluster->getAlgorithm());
        self::assertSame(range(610, 614), $cluster->getMembers());
        self::assertSame('night', $cluster->getParams()['feature_daypart']);

        $timeRange = $cluster->getParams()['time_range'];
        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $timeRange['from']);
        self::assertSame($media[4]->getTakenAt()?->getTimestamp(), $timeRange['to']);
    }

    #[Test]
    public function clustersNightSessionsWhenSceneTagsSuggestNightlife(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            timeGapSeconds: 3 * 3600,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-16 23:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                710 + $i,
                $start->add(new DateInterval('PT' . ($i * 15) . 'M')),
                52.5200 + ($i * 0.0003),
                13.4050 + ($i * 0.0003),
                static function (Media $media): void {
                    $media->setSceneTags([
                        ['label' => 'Party crowd', 'score' => 0.82],
                        ['label' => 'Nightclub interior', 'score' => 0.79],
                    ]);
                },
            );
        }

        $clusters = $strategy->draft($media, Context::fromScope($media));

        self::assertCount(1, $clusters);
        $params = $clusters[0]->getParams();
        self::assertArrayHasKey('scene_tags', $params);
        $labels = array_map(static fn (array $tag): string => $tag['label'], $params['scene_tags']);
        self::assertContains('Party crowd', $labels);
        self::assertContains('Nightclub interior', $labels);
    }

    #[Test]
    public function clustersNightSessionsWhenPoiContextMatchesNightlife(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            timeGapSeconds: 3 * 3600,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $location = $this->makeLocation(
            providerPlaceId: 'poi-nightlife',
            displayName: 'Neon District',
            lat: 52.5203,
            lon: 13.4053,
            configure: static function (Location $location): void {
                $location->setPois([
                    [
                        'id'    => 'node/42',
                        'name'  => 'Neon Club',
                        'names' => [
                            'default'    => 'Neon Club',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'amenity',
                        'categoryValue'  => 'nightclub',
                        'distanceMeters' => 25.0,
                        'tags'           => [
                            'amenity' => 'nightclub',
                        ],
                    ],
                ]);
            },
        );

        $start = new DateTimeImmutable('2024-03-17 00:15:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                810 + $i,
                $start->add(new DateInterval('PT' . ($i * 12) . 'M')),
                52.5202 + ($i * 0.0002),
                13.4052 + ($i * 0.0002),
                static function (Media $media) use ($location): void {
                    $media->setLocation($location);
                },
            );
        }

        $clusters = $strategy->draft($media, Context::fromScope($media));

        self::assertCount(1, $clusters);
        $params = $clusters[0]->getParams();
        self::assertSame('Neon Club', $params['poi_label']);
        self::assertSame('amenity', $params['poi_category_key']);
        self::assertSame('nightclub', $params['poi_category_value']);
    }

    #[Test]
    public function rejectsNightSessionsWithoutSupportingSignals(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            timeGapSeconds: 3 * 3600,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-18 21:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                910 + $i,
                $start->add(new DateInterval('PT' . ($i * 20) . 'M')),
                52.5200 + ($i * 0.0002),
                13.4050 + ($i * 0.0002),
            );
        }

        self::assertSame([], $strategy->draft($media, Context::fromScope($media)));
    }

    #[Test]
    public function rejectsRunsExceedingSpatialRadius(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            timeGapSeconds: 3 * 3600,
            radiusMeters: 50.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-16 22:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                1110 + $i,
                $start->add(new DateInterval('PT' . ($i * 30) . 'M')),
                52.50 + ($i * 0.01),
                13.40 + ($i * 0.01),
                static function (Media $media): void {
                    $media->setFeatures([
                        'calendar' => ['daypart' => 'night'],
                    ]);
                },
            );
        }

        self::assertSame([], $strategy->draft($media, Context::fromScope($media)));
    }

    private function createMedia(
        int $id,
        DateTimeImmutable $takenAt,
        float $lat,
        float $lon,
        ?callable $configure = null,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('nightlife-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            configure: $configure,
        );
    }
}
