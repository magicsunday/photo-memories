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
use MagicSunday\Memories\Clusterer\GoldenHourClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class GoldenHourClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersGoldenHourSequenceBasedOnFeatureFlag(): void
    {
        $strategy = new GoldenHourClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            morningHours: [6, 7, 8],
            eveningHours: [18, 19, 20],
            sessionGapSeconds: 1200,
            minItemsPerRun: 5,
        );

        $base  = new DateTimeImmutable('2024-08-10 13:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->createMedia(
                2700 + $i,
                $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
                true,
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('golden_hour', $clusters[0]->getAlgorithm());
        self::assertSame(range(2700, 2704), $clusters[0]->getMembers());

        $params = $clusters[0]->getParams();
        self::assertArrayHasKey('scene_tags', $params);
        $labels = array_map(static fn (array $tag): string => $tag['label'], $params['scene_tags']);
        self::assertContains('Sunset Skyline', $labels);
        self::assertContains('Golden light', $labels);
    }

    #[Test]
    public function clustersGoldenHourSequenceWithHourFallback(): void
    {
        $strategy = new GoldenHourClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
        );

        $base  = new DateTimeImmutable('2024-08-10 18:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->createMedia(
                2800 + $i,
                $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame(range(2800, 2804), $clusters[0]->getMembers());
    }

    #[Test]
    public function ignoresMediaMarkedAsNotGoldenHour(): void
    {
        $strategy = new GoldenHourClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
        );

        $base  = new DateTimeImmutable('2024-08-10 18:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->createMedia(
                2900 + $i,
                $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
                false,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, ?bool $golden = null): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('golden-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 48.5,
            lon: 9.0,
            configure: static function (Media $media) use ($golden): void {
                if ($golden !== null) {
                    $media->setFeatures([
                        'solar' => ['isGoldenHour' => $golden],
                    ]);
                }

                $media->setSceneTags([
                    ['label' => 'Sunset Skyline', 'score' => 0.91],
                    ['label' => 'Golden light', 'score' => 0.88],
                ]);
            },
        );
    }
}
