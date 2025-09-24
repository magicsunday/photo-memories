<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractWeatherDayClusterStrategy;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds "Rainy Day" clusters when weather hints indicate significant rain on a local day.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 54])]
final class RainyDayClusterStrategy extends AbstractWeatherDayClusterStrategy
{
    public function __construct(
        WeatherHintProviderInterface $weather,
        string $timezone = 'Europe/Berlin',
        private readonly float $minAvgRainProb = 0.6,  // 0..1
        private readonly int $minItemsPerDay = 6
    ) {
        parent::__construct($weather, $timezone);
    }

    public function name(): string
    {
        return 'rainy_day';
    }

    protected function scoreFromHint(array $hint): ?float
    {
        if (!\array_key_exists('rain_prob', $hint)) {
            return null;
        }

        return (float) $hint['rain_prob'];
    }

    protected function passesAverageThreshold(float $average): bool
    {
        return $average >= $this->minAvgRainProb;
    }

    protected function buildParams(float $average, int $count): array
    {
        return [
            'rain_prob' => $average,
        ];
    }

    protected function minItemsPerDay(): int
    {
        return $this->minItemsPerDay;
    }
}
