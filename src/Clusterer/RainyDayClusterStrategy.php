<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds "Rainy Day" clusters when weather hints indicate significant rain on a local day.
 */
final class RainyDayClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly WeatherHintProviderInterface $weather,
        private readonly string $timezone = 'Europe/Berlin',
        private readonly float $minAvgRainProb = 0.6,  // 0..1
        private readonly int $minItemsPerDay = 6
    ) {
        if ($this->minAvgRainProb < 0.0 || $this->minAvgRainProb > 1.0) {
            throw new \InvalidArgumentException('minAvgRainProb must be within 0..1.');
        }
        if ($this->minItemsPerDay < 1) {
            throw new \InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'rainy_day';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        if ($byDay === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minItemsPerDay) {
                continue;
            }

            $sum = 0.0;
            $n   = 0;

            foreach ($list as $m) {
                $hint = $this->weather->getHint($m);
                if ($hint === null) {
                    continue;
                }
                $p = (float) ($hint['rain_prob'] ?? 0.0);
                // Clamp just in case provider goes beyond [0..1]
                if ($p < 0.0) { $p = 0.0; }
                if ($p > 1.0) { $p = 1.0; }

                $sum += $p;
                $n++;
            }

            if ($n === 0) {
                // no usable hints for that day
                continue;
            }

            $avg = $sum / (float) $n;
            if ($avg < $this->minAvgRainProb) {
                continue;
            }

            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'rain_prob'   => $avg,
                    'time_range'  => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
