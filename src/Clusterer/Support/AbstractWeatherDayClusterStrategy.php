<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;

/**
 * Shared base for single-day weather-based clustering strategies.
 */
abstract class AbstractWeatherDayClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        protected readonly WeatherHintProviderInterface $weather,
        string $timezone = 'Europe/Berlin'
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    final protected function groupKey(Media $media): ?string
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
    final protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItemsPerDay()) {
            return null;
        }

        $sum = 0.0;
        $count = 0;

        foreach ($members as $media) {
            $hint = $this->weather->getHint($media);
            if ($hint === null) {
                continue;
            }

            $score = $this->scoreFromHint($hint);
            if ($score === null) {
                continue;
            }

            $sum += $this->clampScore($score);
            $count++;
        }

        if ($count < $this->minHintsPerDay()) {
            return null;
        }

        $average = $sum / (float) $count;
        if (!$this->passesAverageThreshold($average)) {
            return null;
        }

        return $this->buildParams($average, $count);
    }

    protected function minItemsPerDay(): int
    {
        return 1;
    }

    protected function minHintsPerDay(): int
    {
        return 1;
    }

    protected function clampScore(float $score): float
    {
        if ($score < 0.0) {
            return 0.0;
        }

        if ($score > 1.0) {
            return 1.0;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $hint
     */
    abstract protected function scoreFromHint(array $hint): ?float;

    abstract protected function passesAverageThreshold(float $average): bool;

    /**
     * @return array<string, mixed>
     */
    abstract protected function buildParams(float $average, int $count): array;
}
