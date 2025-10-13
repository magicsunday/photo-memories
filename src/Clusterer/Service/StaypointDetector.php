<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\StaypointDetectorInterface;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_slice;
use function count;
use function max;
use function min;
use function round;
use function usort;

/**
 * Default staypoint detection implementation.
 */
final readonly class StaypointDetector implements StaypointDetectorInterface
{
    public function __construct(
        private float $staypointRadiusKm = 0.25,
        private int $minDwellMinutes = 20,
        private ?GeoDbscanHelper $dbscanHelper = null,
        private float $fallbackRadiusKm = 0.18,
        private int $fallbackMinSamples = 3,
        private int $fallbackMinDwellMinutes = 20,
        private float $minAdaptiveRadiusKm = 0.18,
        private float $maxAdaptiveRadiusKm = 0.35,
        private int $minAdaptiveDwellMinutes = 15,
        private int $maxAdaptiveDwellMinutes = 25,
        private float $urbanTravelKm = 5.0,
        private float $ruralTravelKm = 80.0,
        private float $ruralSpotDensity = 0.5,
        private float $urbanSpotDensity = 8.0,
    ) {
        if ($this->staypointRadiusKm <= 0.0) {
            throw new InvalidArgumentException('staypointRadiusKm must be greater than zero.');
        }

        if ($this->minDwellMinutes < 1) {
            throw new InvalidArgumentException('minDwellMinutes must be at least one minute.');
        }

        if ($this->fallbackRadiusKm < 0.0) {
            throw new InvalidArgumentException('fallbackRadiusKm must not be negative.');
        }

        if ($this->fallbackMinSamples < 1) {
            throw new InvalidArgumentException('fallbackMinSamples must be positive.');
        }

        if ($this->fallbackMinDwellMinutes < 0) {
            throw new InvalidArgumentException('fallbackMinDwellMinutes must not be negative.');
        }

        if ($this->minAdaptiveRadiusKm <= 0.0 || $this->maxAdaptiveRadiusKm <= 0.0) {
            throw new InvalidArgumentException('adaptive radius bounds must be greater than zero.');
        }

        if ($this->minAdaptiveRadiusKm > $this->maxAdaptiveRadiusKm) {
            throw new InvalidArgumentException('minAdaptiveRadiusKm must be less than or equal to maxAdaptiveRadiusKm.');
        }

        if ($this->minAdaptiveDwellMinutes < 1 || $this->maxAdaptiveDwellMinutes < 1) {
            throw new InvalidArgumentException('adaptive dwell bounds must be positive.');
        }

        if ($this->minAdaptiveDwellMinutes > $this->maxAdaptiveDwellMinutes) {
            throw new InvalidArgumentException('minAdaptiveDwellMinutes must be less than or equal to maxAdaptiveDwellMinutes.');
        }

        if ($this->urbanTravelKm <= 0.0 || $this->ruralTravelKm <= 0.0) {
            throw new InvalidArgumentException('travel thresholds must be positive.');
        }

        if ($this->urbanTravelKm > $this->ruralTravelKm) {
            throw new InvalidArgumentException('urbanTravelKm must be less than or equal to ruralTravelKm.');
        }

        if ($this->ruralSpotDensity < 0.0 || $this->urbanSpotDensity < 0.0) {
            throw new InvalidArgumentException('spot density thresholds must not be negative.');
        }

        if ($this->ruralSpotDensity > $this->urbanSpotDensity) {
            throw new InvalidArgumentException('ruralSpotDensity must be less than or equal to urbanSpotDensity.');
        }
    }

    public function detect(array $gpsMembers, array $context = []): array
    {
        $parameters = $this->calibrateParameters($gpsMembers, $context);

        $sequential = $this->detectSequentialStaypoints(
            $gpsMembers,
            $parameters['radiusKm'],
            $parameters['minDwellMinutes'],
        );
        if ($sequential !== []) {
            return $sequential;
        }

        return $this->detectWithDbscanFallback(
            $gpsMembers,
            $parameters['fallbackRadiusKm'],
            $parameters['fallbackMinSamples'],
            $parameters['fallbackMinDwellMinutes'],
        );
    }

    /**
     * @param list<Media> $gpsMembers
     *
     * @return list<array{lat:float,lon:float,start:int,end:int,dwell:int}>
     */
    private function detectSequentialStaypoints(array $gpsMembers, float $radiusKm, int $minDwellMinutes): array
    {
        $count = count($gpsMembers);
        if ($count < 2) {
            return [];
        }

        $staypoints        = [];
        $minDwellSeconds   = max(1, $minDwellMinutes) * 60;
        $currentIndex      = 0;

        while ($currentIndex < $count - 1) {
            $startMedia = $gpsMembers[$currentIndex];
            $startTime  = $startMedia->getTakenAt();
            $startLat   = $startMedia->getGpsLat();
            $startLon   = $startMedia->getGpsLon();

            if (!$startTime instanceof DateTimeImmutable || $startLat === null || $startLon === null) {
                ++$currentIndex;
                continue;
            }

            $windowEnd = $currentIndex + 1;
            while ($windowEnd < $count) {
                $candidate     = $gpsMembers[$windowEnd];
                $candidateTime = $candidate->getTakenAt();
                $candLat       = $candidate->getGpsLat();
                $candLon       = $candidate->getGpsLon();

                if (!$candidateTime instanceof DateTimeImmutable || $candLat === null || $candLon === null) {
                    ++$windowEnd;
                    continue;
                }

                $distanceKm = MediaMath::haversineDistanceInMeters(
                    $startLat,
                    $startLon,
                    $candLat,
                    $candLon,
                ) / 1000.0;

                if ($distanceKm > $radiusKm) {
                    break;
                }

                ++$windowEnd;
            }

            $endIndex = $windowEnd - 1;
            if ($endIndex <= $currentIndex) {
                ++$currentIndex;
                continue;
            }

            $segment  = array_slice($gpsMembers, $currentIndex, $endIndex - $currentIndex + 1);
            $endMedia = $segment[count($segment) - 1];
            $endTime  = $endMedia->getTakenAt();

            if (!$endTime instanceof DateTimeImmutable) {
                ++$currentIndex;
                continue;
            }

            $dwell = $endTime->getTimestamp() - $startTime->getTimestamp();
            if ($dwell < $minDwellSeconds) {
                ++$currentIndex;
                continue;
            }

            $centroid = MediaMath::centroid($segment);

            $staypoints[] = [
                'lat'   => $centroid['lat'],
                'lon'   => $centroid['lon'],
                'start' => $startTime->getTimestamp(),
                'end'   => $endTime->getTimestamp(),
                'dwell' => $dwell,
            ];

            $currentIndex = $endIndex + 1;
        }

        return $staypoints;
    }

    /**
     * @param list<Media> $gpsMembers
     * @param array{travelKm?:float,spotCount?:int,spotDensity?:float} $context
     *
     * @return array{radiusKm:float,minDwellMinutes:int,fallbackRadiusKm:float,fallbackMinSamples:int,fallbackMinDwellMinutes:int}
     */
    private function calibrateParameters(array $gpsMembers, array $context): array
    {
        $travelKm = $context['travelKm'] ?? $this->estimateTravelKm($gpsMembers);
        $travelKm = max(0.0, (float) $travelKm);

        $spotCount   = isset($context['spotCount']) ? max(0, (int) $context['spotCount']) : null;
        $spotDensity = $context['spotDensity'] ?? null;

        if ($spotDensity === null) {
            $spotDensity = $this->estimateSpotDensity($gpsMembers, $spotCount, $travelKm);
        }

        if ($gpsMembers === [] || ($travelKm === 0.0 && $spotDensity === 0.0)) {
            return [
                'radiusKm'                => $this->staypointRadiusKm,
                'minDwellMinutes'         => $this->minDwellMinutes,
                'fallbackRadiusKm'        => $this->fallbackRadiusKm,
                'fallbackMinSamples'      => $this->fallbackMinSamples,
                'fallbackMinDwellMinutes' => $this->fallbackMinDwellMinutes,
            ];
        }

        $travelFactor  = $this->normalize($travelKm, $this->urbanTravelKm, $this->ruralTravelKm);
        $densityFactor = 1.0 - $this->normalize($spotDensity, $this->ruralSpotDensity, $this->urbanSpotDensity);
        $mix           = max(0.0, min(1.0, ($travelFactor + $densityFactor) / 2.0));

        $radiusRange = $this->maxAdaptiveRadiusKm - $this->minAdaptiveRadiusKm;
        $radiusKm    = $radiusRange > 0.0
            ? $this->minAdaptiveRadiusKm + $mix * $radiusRange
            : $this->staypointRadiusKm;

        $dwellRange       = $this->maxAdaptiveDwellMinutes - $this->minAdaptiveDwellMinutes;
        $minDwellMinutes  = $dwellRange > 0
            ? (int) round($this->minAdaptiveDwellMinutes + $mix * $dwellRange)
            : $this->minDwellMinutes;
        $radiusKm         = max($this->minAdaptiveRadiusKm, min($radiusKm, $this->maxAdaptiveRadiusKm));
        $minDwellMinutes  = max($this->minAdaptiveDwellMinutes, min($minDwellMinutes, $this->maxAdaptiveDwellMinutes));

        return [
            'radiusKm'                => $radiusKm,
            'minDwellMinutes'         => $minDwellMinutes,
            'fallbackRadiusKm'        => max($radiusKm, $this->fallbackRadiusKm),
            'fallbackMinSamples'      => $this->fallbackMinSamples,
            'fallbackMinDwellMinutes' => max($minDwellMinutes, $this->fallbackMinDwellMinutes),
        ];
    }

    /**
     * @param list<Media> $gpsMembers
     */
    private function estimateTravelKm(array $gpsMembers): float
    {
        $count = count($gpsMembers);
        if ($count < 2) {
            return 0.0;
        }

        $sorted = $gpsMembers;
        usort($sorted, static fn (Media $a, Media $b): int => $a->getTakenAt()?->getTimestamp() <=> $b->getTakenAt()?->getTimestamp());

        $travel   = 0.0;
        $previous = null;
        foreach ($sorted as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat === null || $lon === null) {
                continue;
            }

            if ($previous instanceof Media) {
                $prevLat = $previous->getGpsLat();
                $prevLon = $previous->getGpsLon();
                if ($prevLat !== null && $prevLon !== null) {
                    $travel += MediaMath::haversineDistanceInMeters($prevLat, $prevLon, $lat, $lon) / 1000.0;
                }
            }

            $previous = $media;
        }

        return $travel;
    }

    /**
     * @param list<Media> $gpsMembers
     */
    private function estimateSpotDensity(array $gpsMembers, ?int $spotCount, float $travelKm): float
    {
        $count = count($gpsMembers);
        if ($count === 0) {
            return 0.0;
        }

        if ($spotCount !== null) {
            return $spotCount / max(0.5, $travelKm);
        }

        $denominator = max(0.5, $travelKm);
        $densityBase = (float) $count;

        if ($travelKm < 0.5) {
            $span = $this->estimateDurationSeconds($gpsMembers);
            if ($span > 0) {
                $hours       = max(0.25, $span / 3600.0);
                $densityBase = max($densityBase, $count / $hours);
            }
        }

        return $densityBase / $denominator;
    }

    /**
     * @param list<Media> $gpsMembers
     */
    private function estimateDurationSeconds(array $gpsMembers): int
    {
        $first = null;
        $last  = null;

        foreach ($gpsMembers as $media) {
            $timestamp = $media->getTakenAt()?->getTimestamp();
            if ($timestamp === null) {
                continue;
            }

            $first ??= $timestamp;
            $last    = $timestamp;
        }

        if ($first === null || $last === null) {
            return 0;
        }

        return max(0, $last - $first);
    }

    private function normalize(float $value, float $low, float $high): float
    {
        if ($high <= $low) {
            return 0.0;
        }

        if ($value <= $low) {
            return 0.0;
        }

        if ($value >= $high) {
            return 1.0;
        }

        return ($value - $low) / ($high - $low);
    }

    /**
     * @param list<Media> $gpsMembers
     *
     * @return list<array{lat:float,lon:float,start:int,end:int,dwell:int}>
     */
    private function detectWithDbscanFallback(
        array $gpsMembers,
        float $radiusKm,
        int $minSamples,
        int $minDwellMinutes,
    ): array {
        if ($this->dbscanHelper === null || $radiusKm <= 0.0) {
            return [];
        }

        if ($gpsMembers === []) {
            return [];
        }

        $clusters = $this->dbscanHelper->clusterMedia(
            $gpsMembers,
            $radiusKm,
            max(1, $minSamples),
        );

        if ($clusters['clusters'] === []) {
            return [];
        }

        $staypoints      = [];
        $minDwellSeconds = max(0, $minDwellMinutes) * 60;

        foreach ($clusters['clusters'] as $cluster) {
            if ($cluster === []) {
                continue;
            }

            usort($cluster, static function (Media $a, Media $b): int {
                $left  = $a->getTakenAt();
                $right = $b->getTakenAt();

                $leftTimestamp  = $left instanceof DateTimeImmutable ? $left->getTimestamp() : 0;
                $rightTimestamp = $right instanceof DateTimeImmutable ? $right->getTimestamp() : 0;

                return $leftTimestamp <=> $rightTimestamp;
            });

            $first = $cluster[0];
            $last  = $cluster[count($cluster) - 1];

            $start = $first->getTakenAt();
            $end   = $last->getTakenAt();

            if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
                continue;
            }

            $dwell = $end->getTimestamp() - $start->getTimestamp();
            if ($dwell < $minDwellSeconds) {
                continue;
            }

            $centroid = MediaMath::centroid($cluster);

            $staypoints[] = [
                'lat'   => $centroid['lat'],
                'lon'   => $centroid['lon'],
                'start' => $start->getTimestamp(),
                'end'   => $end->getTimestamp(),
                'dwell' => $dwell,
            ];
        }

        usort($staypoints, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'];
        });

        return $staypoints;
    }
}
