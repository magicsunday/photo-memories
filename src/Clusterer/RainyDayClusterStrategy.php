<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds "Rainy Day" clusters when weather hints indicate significant rain on a local day.
 */
final class RainyDayClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

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

        $timestampedItems = $this->filterTimestampedItems($items);

        if ($timestampedItems === []) {
            return [];
        }

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($timestampedItems as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        if ($byDay === []) {
            return [];
        }

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var array<string, float> $avgRain */
        $avgRain = [];
        $rainyDays = $this->filterGroupsWithKeys(
            $eligibleDays,
            function (array $list, string $day) use (&$avgRain): bool {
                $sum = 0.0;
                $n   = 0;

                foreach ($list as $m) {
                    $hint = $this->weather->getHint($m);
                    if ($hint === null) {
                        continue;
                    }
                    $p = (float) ($hint['rain_prob'] ?? 0.0);
                    if ($p < 0.0) { $p = 0.0; }
                    if ($p > 1.0) { $p = 1.0; }

                    $sum += $p;
                    $n++;
                }

                if ($n === 0) {
                    return false;
                }

                $avg = $sum / (float) $n;
                if ($avg < $this->minAvgRainProb) {
                    return false;
                }

                $avgRain[$day] = $avg;

                return true;
            }
        );

        if ($rainyDays === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($rainyDays as $day => $list) {
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'rain_prob'   => $avgRain[$day],
                    'time_range'  => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }

}
