<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds "Sunny Day" clusters when weather hints indicate strong sunshine on a local day.
 * Priority: use sun_prob; fallback to 1 - cloud_cover; fallback to 1 - rain_prob.
 */
final class SunnyDayClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly WeatherHintProviderInterface $weather,
        private readonly string $timezone = 'Europe/Berlin',
        private readonly float $minAvgSunScore = 0.65, // 0..1
        private readonly int $minItemsPerDay = 6,
        private readonly int $minHintsPerDay = 3
    ) {
    }

    public function name(): string
    {
        return 'sunny_day';
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

                // Prefer explicit sun_prob
                if (\array_key_exists('sun_prob', $hint)) {
                    $p = (float) $hint['sun_prob'];
                } elseif (\array_key_exists('cloud_cover', $hint)) {
                    // 0..1 cloud cover => sunshine proxy
                    $p = 1.0 - (float) $hint['cloud_cover'];
                } elseif (\array_key_exists('rain_prob', $hint)) {
                    // conservative proxy from rain probability
                    $p = \max(0.0, 1.0 - (float) $hint['rain_prob']);
                } else {
                    continue;
                }

                // clamp to [0..1]
                if ($p < 0.0) { $p = 0.0; }
                if ($p > 1.0) { $p = 1.0; }

                $sum += $p;
                $n++;
            }

            if ($n < $this->minHintsPerDay) {
                continue;
            }

            $avg = $sum / (float) $n;
            if ($avg < $this->minAvgSunScore) {
                continue;
            }

            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'sun_score'  => $avg,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
