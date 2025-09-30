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
use MagicSunday\Memories\Clusterer\Contract\StaypointDetectorInterface;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function assert;
use function count;
use function usort;

/**
 * Computes GPS-based metrics for day summaries.
 */
final class GpsMetricsStage implements DaySummaryStageInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly GeoDbscanHelper $dbscanHelper,
        private readonly StaypointDetectorInterface $staypointDetector,
        private readonly float $gpsOutlierRadiusKm = 1.0,
        private readonly int $gpsOutlierMinSamples = 3,
        private readonly int $minItemsPerDay = 3,
    ) {
        if ($this->gpsOutlierRadiusKm <= 0.0) {
            throw new InvalidArgumentException('gpsOutlierRadiusKm must be > 0.');
        }

        if ($this->gpsOutlierMinSamples < 2) {
            throw new InvalidArgumentException('gpsOutlierMinSamples must be >= 2.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
    }

    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        foreach ($days as &$summary) {
            $summary['gpsMembers'] = $this->filterGpsOutliers(
                $summary['gpsMembers'],
                $this->gpsOutlierRadiusKm,
                $this->gpsOutlierMinSamples,
            );

            $summary['maxDistanceKm'] = 0.0;
            $summary['distanceSum']   = 0.0;
            $summary['distanceCount'] = 0;
            $summary['avgDistanceKm'] = 0.0;
            $summary['travelKm']      = 0.0;

            $gpsMembers = $summary['gpsMembers'];
            if ($gpsMembers !== []) {
                usort($gpsMembers, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $travelKm = 0.0;
                $previous = null;
                foreach ($gpsMembers as $gpsMedia) {
                    $lat = $gpsMedia->getGpsLat();
                    $lon = $gpsMedia->getGpsLon();
                    $takenAt = $gpsMedia->getTakenAt();
                    assert($lat !== null && $lon !== null && $takenAt instanceof DateTimeImmutable);

                    if ($previous instanceof Media) {
                        $travelKm += MediaMath::haversineDistanceInMeters(
                            (float) $previous->getGpsLat(),
                            (float) $previous->getGpsLon(),
                            (float) $lat,
                            (float) $lon,
                        ) / 1000.0;
                    }

                    $previous = $gpsMedia;
                }

                $summary['travelKm'] = $travelKm;

                $centroid = MediaMath::centroid($gpsMembers);
                foreach ($gpsMembers as $gpsMedia) {
                    $distance = MediaMath::haversineDistanceInMeters(
                        (float) $gpsMedia->getGpsLat(),
                        (float) $gpsMedia->getGpsLon(),
                        (float) $centroid['lat'],
                        (float) $centroid['lon'],
                    ) / 1000.0;

                    $summary['distanceSum']   += $distance;
                    ++$summary['distanceCount'];

                    if ($distance > $summary['maxDistanceKm']) {
                        $summary['maxDistanceKm'] = $distance;
                    }
                }

                if ($summary['distanceCount'] > 0) {
                    $summary['avgDistanceKm'] = $summary['distanceSum'] / $summary['distanceCount'];
                }

                $summary['firstGpsMedia'] = $gpsMembers[0];
                $summary['lastGpsMedia']  = $gpsMembers[count($gpsMembers) - 1];
                $summary['staypoints']    = $this->staypointDetector->detect($gpsMembers);

                $clusters = $this->dbscanHelper->clusterMedia(
                    $gpsMembers,
                    $this->gpsOutlierRadiusKm,
                    $this->gpsOutlierMinSamples,
                );

                $summary['spotClusters']     = $clusters['clusters'];
                $summary['spotNoise']        = $clusters['noise'];
                $summary['spotCount']        = count($clusters['clusters']);
                $summary['spotNoiseSamples'] = count($clusters['noise']);

                $dwellSeconds = 0;
                foreach ($summary['staypoints'] as $staypoint) {
                    $dwellSeconds += $staypoint['dwell'];
                }

                $summary['spotDwellSeconds'] = $dwellSeconds;
            }

            $summary['sufficientSamples'] = $summary['photoCount'] >= $this->minItemsPerDay;
        }

        unset($summary);

        return $days;
    }
}
