<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds "Rainy Day" clusters when weather hints indicate significant rain on a local day.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 54])]
final class RainyDayClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly WeatherHintProviderInterface $weather,
        string $timezone = 'Europe/Berlin',
        private readonly float $minAvgRainProb = 0.6,  // 0..1
        private readonly int $minItemsPerDay = 6
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function name(): string
    {
        return 'rainy_day';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $takenAt->setTimezone($this->timezone)->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItemsPerDay) {
            return null;
        }

        $sum = 0.0;
        $count = 0;

        foreach ($members as $media) {
            $hint = $this->weather->getHint($media);
            if ($hint === null) {
                continue;
            }

            $probability = (float) ($hint['rain_prob'] ?? 0.0);
            if ($probability < 0.0) {
                $probability = 0.0;
            } elseif ($probability > 1.0) {
                $probability = 1.0;
            }

            $sum += $probability;
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        $average = $sum / (float) $count;
        if ($average < $this->minAvgRainProb) {
            return null;
        }

        return [
            'rain_prob' => $average,
        ];
    }
}
