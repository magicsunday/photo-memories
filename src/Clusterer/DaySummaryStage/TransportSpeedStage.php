<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function assert;
use function count;
use function max;
use function usort;

/**
 * Computes transport leg speeds for each day summary.
 */
final readonly class TransportSpeedStage implements DaySummaryStageInterface
{
    private const HIGH_TRAVEL_THRESHOLD_KM = 150.0;

    public function __construct(
        private float $minLegDurationMinutes = 5.0,
        private float $minLegDistanceKm = 10.0,
        private float $highSpeedThresholdKmh = 100.0,
    ) {
        if ($this->minLegDurationMinutes <= 0.0) {
            throw new InvalidArgumentException('minLegDurationMinutes must be > 0.');
        }

        if ($this->minLegDistanceKm <= 0.0) {
            throw new InvalidArgumentException('minLegDistanceKm must be > 0.');
        }

        if ($this->highSpeedThresholdKmh <= 0.0) {
            throw new InvalidArgumentException('highSpeedThresholdKmh must be > 0.');
        }
    }

    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        foreach ($days as &$summary) {
            $summary['maxSpeedKmh']         = 0.0;
            $summary['avgSpeedKmh']         = 0.0;
            $summary['hasHighSpeedTransit'] = false;

            $travelKm = (float) ($summary['travelKm'] ?? 0.0);
            if ($travelKm > self::HIGH_TRAVEL_THRESHOLD_KM) {
                $summary['hasHighSpeedTransit'] = true;
            }

            $gpsMembers = $summary['gpsMembers'] ?? [];
            if ($gpsMembers === [] || count($gpsMembers) < 2) {
                continue;
            }

            usort($gpsMembers, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

            $totalSpeedKmh = 0.0;
            $speedSamples  = 0;
            $maxSpeedKmh   = 0.0;

            $previous = null;
            foreach ($gpsMembers as $gpsMedia) {
                $currentLat  = $gpsMedia->getGpsLat();
                $currentLon  = $gpsMedia->getGpsLon();
                $currentTime = $gpsMedia->getTakenAt();
                assert($currentLat !== null && $currentLon !== null && $currentTime instanceof DateTimeImmutable);

                if ($previous instanceof Media) {
                    $previousLat  = $previous->getGpsLat();
                    $previousLon  = $previous->getGpsLon();
                    $previousTime = $previous->getTakenAt();
                    assert($previousLat !== null && $previousLon !== null && $previousTime instanceof DateTimeImmutable);

                    $deltaSeconds = $currentTime->getTimestamp() - $previousTime->getTimestamp();
                    if ($deltaSeconds <= 0) {
                        $previous = $gpsMedia;
                        continue;
                    }

                    $deltaMinutes = $deltaSeconds / 60.0;
                    if ($deltaMinutes < $this->minLegDurationMinutes) {
                        $previous = $gpsMedia;
                        continue;
                    }

                    $distanceKm = MediaMath::haversineDistanceInMeters(
                        $previousLat,
                        $previousLon,
                        $currentLat,
                        $currentLon,
                    ) / 1000.0;

                    if ($distanceKm < $this->minLegDistanceKm) {
                        $previous = $gpsMedia;
                        continue;
                    }

                    $speedKmh = ($distanceKm / $deltaSeconds) * 3600.0;
                    $totalSpeedKmh += $speedKmh;
                    ++$speedSamples;

                    $maxSpeedKmh = max($maxSpeedKmh, $speedKmh);
                }

                $previous = $gpsMedia;
            }

            if ($speedSamples > 0) {
                $summary['avgSpeedKmh'] = $totalSpeedKmh / $speedSamples;
                $summary['maxSpeedKmh'] = $maxSpeedKmh;
                if ($maxSpeedKmh >= $this->highSpeedThresholdKmh) {
                    $summary['hasHighSpeedTransit'] = true;
                }
            }
        }

        unset($summary);

        return $days;
    }
}
