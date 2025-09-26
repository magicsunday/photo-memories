<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\RainyDayClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RainyDayClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersDayWithHighRainProbability(): void
    {
        $provider = new RainHintProvider([
            2900 => ['rain_prob' => 0.7],
            2901 => ['rain_prob' => 0.8],
            2902 => ['rain_prob' => 0.75],
            2903 => ['rain_prob' => 0.72],
            2904 => ['rain_prob' => 0.78],
            2905 => ['rain_prob' => 0.74],
        ]);
        $strategy = new RainyDayClusterStrategy(
            weather: $provider,
            timezone: 'Europe/Berlin',
            minAvgRainProb: 0.6,
            minItemsPerDay: 6,
        );

        $base = new DateTimeImmutable('2024-10-01 09:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->createMedia(2900 + $i, $base->add(new DateInterval('PT' . ($i * 900) . 'S')));
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('rainy_day', $clusters[0]->getAlgorithm());
        self::assertSame(range(2900, 2905), $clusters[0]->getMembers());
    }

    #[Test]
    public function skipsDryDays(): void
    {
        $provider = new RainHintProvider([
            3000 => ['rain_prob' => 0.2],
            3001 => ['rain_prob' => 0.1],
            3002 => ['rain_prob' => 0.3],
            3003 => ['rain_prob' => 0.25],
            3004 => ['rain_prob' => 0.2],
            3005 => ['rain_prob' => 0.3],
        ]);
        $strategy = new RainyDayClusterStrategy(weather: $provider);

        $base = new DateTimeImmutable('2024-10-02 09:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->createMedia(3000 + $i, $base->add(new DateInterval('PT' . ($i * 900) . 'S')));
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/rainy-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat(47.5);
        $media->setGpsLon(7.6);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}

final class RainHintProvider implements WeatherHintProviderInterface
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
        return $this->hints[$media->getId()] ?? null;
    }
}
