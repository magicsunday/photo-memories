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
 * Builds "Sunny Day" clusters when weather hints indicate strong sunshine on a local day.
 * Priority: use sun_prob; fallback to 1 - cloud_cover; fallback to 1 - rain_prob.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 56])]
final class SunnyDayClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly WeatherHintProviderInterface $weather,
        string $timezone = 'Europe/Berlin',
        private readonly float $minAvgSunScore = 0.65, // 0..1
        private readonly int $minItemsPerDay = 6,
        private readonly int $minHintsPerDay = 3
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function name(): string
    {
        return 'sunny_day';
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

            if (\array_key_exists('sun_prob', $hint)) {
                $score = (float) $hint['sun_prob'];
            } elseif (\array_key_exists('cloud_cover', $hint)) {
                $score = 1.0 - (float) $hint['cloud_cover'];
            } elseif (\array_key_exists('rain_prob', $hint)) {
                $score = \max(0.0, 1.0 - (float) $hint['rain_prob']);
            } else {
                continue;
            }

            if ($score < 0.0) {
                $score = 0.0;
            } elseif ($score > 1.0) {
                $score = 1.0;
            }

            $sum += $score;
            $count++;
        }

        if ($count < $this->minHintsPerDay) {
            return null;
        }

        $average = $sum / (float) $count;
        if ($average < $this->minAvgSunScore) {
            return null;
        }

        return [
            'sun_score' => $average,
        ];
    }
}
