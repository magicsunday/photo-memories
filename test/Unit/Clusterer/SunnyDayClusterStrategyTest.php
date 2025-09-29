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
use MagicSunday\Memories\Clusterer\SunnyDayClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SunnyDayClusterStrategyTest extends TestCase
{
    #[Test]
    public function formsClusterWhenAverageSunScoreHigh(): void
    {
        $provider = new InMemoryWeatherProvider(
            hints: [
                2500 => ['sun_prob' => 0.9],
                2501 => ['sun_prob' => 0.85],
                2502 => ['sun_prob' => 0.8],
                2503 => ['sun_prob' => 0.95],
                2504 => ['sun_prob' => 0.88],
                2505 => ['sun_prob' => 0.9],
            ]
        );
        $strategy = new SunnyDayClusterStrategy(
            weather: $provider,
            timezone: 'Europe/Berlin',
            minAvgSunScore: 0.7,
            minItemsPerDay: 6,
            minHintsPerDay: 3,
        );

        $base  = new DateTimeImmutable('2024-05-01 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->createMedia(2500 + $i, $base->add(new DateInterval('PT' . ($i * 600) . 'S')));
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('sunny_day', $cluster->getAlgorithm());
        self::assertSame(range(2500, 2505), $cluster->getMembers());
        self::assertGreaterThanOrEqual(0.7, $cluster->getParams()['sun_score']);
    }

    #[Test]
    public function skipsDaysWithLowSunScore(): void
    {
        $provider = new InMemoryWeatherProvider(
            hints: [
                2600 => ['sun_prob' => 0.4],
                2601 => ['sun_prob' => 0.5],
                2602 => ['sun_prob' => 0.45],
                2603 => ['sun_prob' => 0.55],
                2604 => ['sun_prob' => 0.5],
                2605 => ['sun_prob' => 0.45],
            ]
        );
        $strategy = new SunnyDayClusterStrategy(weather: $provider);

        $base  = new DateTimeImmutable('2024-05-02 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->createMedia(2600 + $i, $base->add(new DateInterval('PT' . ($i * 600) . 'S')));
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('sunny-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 48.0,
            lon: 11.0,
        );
    }
}

final class InMemoryWeatherProvider implements WeatherHintProviderInterface
{
    /** @var array<int,array<string,float>> */
    private array $hints;

    /**
     * @param array<int,array<string,float>> $hints
     */
    public function __construct(array $hints)
    {
        $this->hints = $hints;
    }

    public function getHint(Media $media): ?array
    {
        $id = $media->getId();

        return $this->hints[$id] ?? null;
    }
}
