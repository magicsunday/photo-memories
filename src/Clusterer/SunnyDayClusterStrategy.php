<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Utility\MediaMath;

use function array_key_exists;
use function array_map;
use function assert;
use function max;

/**
 * Builds "Sunny Day" clusters when weather hints indicate strong sunshine on a local day.
 * Priority: use sun_prob; fallback to 1 - cloud_cover; fallback to 1 - rain_prob.
 */
final readonly class SunnyDayClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private WeatherHintProviderInterface $weather,
        private string $timezone = 'Europe/Berlin',
        private float $minAvgSunScore = 0.65, // 0..1
        private int $minItemsPerDay = 6,
        private int $minHintsPerDay = 3,
    ) {
        if ($this->minAvgSunScore < 0.0 || $this->minAvgSunScore > 1.0) {
            throw new InvalidArgumentException('minAvgSunScore must be within 0..1.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minHintsPerDay < 1) {
            throw new InvalidArgumentException('minHintsPerDay must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'sunny_day';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $local = $t->setTimezone($tz);
            $key   = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        if ($byDay === []) {
            return [];
        }

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var array<string, float> $avgSun */
        $avgSun    = [];
        $sunnyDays = $this->filterGroupsWithKeys(
            $eligibleDays,
            function (array $list, string $day) use (&$avgSun): bool {
                $sum = 0.0;
                $n   = 0;

                foreach ($list as $m) {
                    $hint = $this->weather->getHint($m);
                    if ($hint === null) {
                        continue;
                    }

                    if (array_key_exists('sun_prob', $hint)) {
                        $p = (float) $hint['sun_prob'];
                    } elseif (array_key_exists('cloud_cover', $hint)) {
                        $p = 1.0 - (float) $hint['cloud_cover'];
                    } elseif (array_key_exists('rain_prob', $hint)) {
                        $p = max(0.0, 1.0 - $hint['rain_prob']);
                    } else {
                        continue;
                    }

                    if ($p < 0.0) {
                        $p = 0.0;
                    }

                    if ($p > 1.0) {
                        $p = 1.0;
                    }

                    $sum += $p;
                    ++$n;
                }

                if ($n < $this->minHintsPerDay) {
                    return false;
                }

                $avg = $sum / (float) $n;
                if ($avg < $this->minAvgSunScore) {
                    return false;
                }

                $avgSun[$day] = $avg;

                return true;
            }
        );

        if ($sunnyDays === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($sunnyDays as $day => $list) {
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'sun_score'  => $avgSun[$day],
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
