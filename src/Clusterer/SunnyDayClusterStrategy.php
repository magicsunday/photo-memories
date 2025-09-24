<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractWeatherDayClusterStrategy;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds "Sunny Day" clusters when weather hints indicate strong sunshine on a local day.
 * Priority: use sun_prob; fallback to 1 - cloud_cover; fallback to 1 - rain_prob.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 56])]
final class SunnyDayClusterStrategy extends AbstractWeatherDayClusterStrategy
{
    public function __construct(
        WeatherHintProviderInterface $weather,
        string $timezone = 'Europe/Berlin',
        private readonly float $minAvgSunScore = 0.65, // 0..1
        private readonly int $minItemsPerDay = 6,
        private readonly int $minHintsPerDay = 3
    ) {
        parent::__construct($weather, $timezone);
    }

    public function name(): string
    {
        return 'sunny_day';
    }

    protected function scoreFromHint(array $hint): ?float
    {
        if (\array_key_exists('sun_prob', $hint)) {
            return (float) $hint['sun_prob'];
        }

        if (\array_key_exists('cloud_cover', $hint)) {
            return 1.0 - (float) $hint['cloud_cover'];
        }

        if (\array_key_exists('rain_prob', $hint)) {
            return 1.0 - (float) $hint['rain_prob'];
        }

        return null;
    }

    protected function passesAverageThreshold(float $average): bool
    {
        return $average >= $this->minAvgSunScore;
    }

    protected function buildParams(float $average, int $count): array
    {
        return [
            'sun_score' => $average,
        ];
    }

    protected function minItemsPerDay(): int
    {
        return $this->minItemsPerDay;
    }

    protected function minHintsPerDay(): int
    {
        return $this->minHintsPerDay;
    }
}
