<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\DaySummaryBuilderInterface;
use MagicSunday\Memories\Clusterer\Contract\HomeLocatorInterface;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use MagicSunday\Memories\Clusterer\Contract\VacationSegmentAssemblerInterface;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function sprintf;
use function strcmp;
use function usort;

/**
 * Orchestrates the vacation clustering flow using dedicated collaborators.
 *
 * The strategy creates vacation clusters by removing media without
 * timestamps, ordering all remaining captures, inferring the travellers'
 * home location and finally assembling multi-day vacation segments. Each
 * stage is delegated to dedicated services to keep responsibilities focused
 * and composable.
 */
final readonly class VacationClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;

    private const MONITORING_JOB = 'cluster.vacation';

    /**
     * @param HomeLocatorInterface            $homeLocator       Identifies the home location used as a reference point.
     * @param DaySummaryBuilderInterface      $daySummaryBuilder Builds daily summaries that feed the segment assembler.
     * @param VacationSegmentAssemblerInterface $segmentAssembler Detects high level vacation segments from day summaries.
     */
    public function __construct(
        private HomeLocatorInterface $homeLocator,
        private DaySummaryBuilderInterface $daySummaryBuilder,
        private VacationSegmentAssemblerInterface $segmentAssembler,
        private ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
    }

    /**
     * Returns the identifier of the cluster strategy.
     */
    public function name(): string
    {
        return 'vacation';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        return $this->clusterInternal($items, null);
    }

    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, callable $update): array
    {
        return $this->clusterInternal($items, $update);
    }

    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void|null $update
     *
     * @return list<ClusterDraft>
     */
    private function clusterInternal(array $items, ?callable $update): array
    {
        $totalCount = count($items);
        $this->emitMonitoring('start', [
            'total_count' => $totalCount,
        ]);

        $step  = 0;
        $steps = 6;

        // Cluster processing requires reliable timestamps to establish the chronology of the trip.
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d: Zeitstempel prüfen …', $step + 1, $steps));
        $timestamped = $this->filterTimestampedItems($items);
        $timestampedCount = count($timestamped);

        ++$step;
        $this->notifyProgress(
            $update,
            $step,
            $steps,
            sprintf('Schritt %d/%d abgeschlossen – %d Medien mit Zeitstempel', $step, $steps, $timestampedCount)
        );

        if ($timestampedCount === 0) {
            $this->emitMonitoring('skipped', [
                'reason' => 'no_timestamped_media',
                'total_count' => $totalCount,
            ]);

            $this->notifyProgress($update, $steps, $steps, 'Abbruch: Keine Medien mit Zeitstempel');

            return [];
        }

        $this->emitMonitoring('filtered', [
            'total_count' => $totalCount,
            'timestamped_count' => $timestampedCount,
            'filtered_count' => $totalCount - $timestampedCount,
        ]);

        // Ensure chronological ordering so day summaries receive a consistent sequence.
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d: Chronologie herstellen …', $step + 1, $steps));
        $ordered = $this->sortChronologically($timestamped);
        ++$step;
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d abgeschlossen – Chronologie erstellt', $step, $steps));

        // Determine the traveller's base location, which anchors day grouping and trip detection.
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d: Zuhause bestimmen …', $step + 1, $steps));
        $home = $this->homeLocator->determineHome($ordered);
        if ($home === null) {
            $this->emitMonitoring('skipped', [
                'reason' => 'no_home_location',
                'timestamped_count' => $timestampedCount,
            ]);

            $this->notifyProgress($update, $steps, $steps, 'Abbruch: Zuhause konnte nicht bestimmt werden');

            return [];
        }

        ++$step;
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d abgeschlossen – Zuhause bestimmt', $step, $steps));

        $this->emitMonitoring('home_determined', [
            'timestamped_count' => $timestampedCount,
            'home_lat' => $home['lat'],
            'home_lon' => $home['lon'],
            'home_radius_km' => $home['radius_km'],
            'home_country' => $home['country'],
            'home_timezone_offset' => $home['timezone_offset'],
        ]);

        // Build per-day statistics (distances, stay points, etc.) required to evaluate candidate vacation runs.
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d: Tagesübersichten erstellen …', $step + 1, $steps));
        $days = $this->daySummaryBuilder->buildDaySummaries($ordered, $home);
        $dayCount = count($days);

        ++$step;
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d abgeschlossen – %d Tagesübersichten', $step, $steps, $dayCount));

        if ($dayCount === 0) {
            $this->emitMonitoring('skipped', [
                'reason' => 'no_day_summaries',
                'timestamped_count' => $timestampedCount,
            ]);

            $this->notifyProgress($update, $steps, $steps, 'Abbruch: Keine Tageszusammenfassungen');

            return [];
        }

        $metrics = $this->summariseDays($days);

        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d: Kennzahlen berechnen …', $step + 1, $steps));

        ++$step;
        $this->notifyProgress(
            $update,
            $step,
            $steps,
            sprintf('Schritt %d/%d abgeschlossen – %.1f km Reiseweg', $step, $steps, $metrics['total_travel_km'])
        );

        $this->emitMonitoring('days_aggregated', [
            'day_count' => $dayCount,
            'away_day_count' => $metrics['away_day_count'],
            'away_candidate_count' => $metrics['away_candidate_count'],
            'total_travel_km' => $metrics['total_travel_km'],
            'max_distance_km' => $metrics['max_distance_km'],
            'avg_travel_km' => $metrics['avg_travel_km'],
        ]);

        // Translate day summaries into scored vacation segments ready for persistence.
        $this->notifyProgress($update, $step, $steps, sprintf('Schritt %d/%d: Segmente erkennen …', $step + 1, $steps));
        $segments = $this->segmentAssembler->detectSegments($days, $home);
        $segmentCount = count($segments);

        if ($segmentCount === 0) {
            $this->emitMonitoring('skipped', [
                'reason' => 'no_segments_detected',
                'day_count' => $dayCount,
                'timestamped_count' => $timestampedCount,
            ]);

            $this->notifyProgress($update, $steps, $steps, 'Abbruch: Keine Urlaubssegmente gefunden');

            return [];
        }

        ++$step;
        $this->notifyProgress(
            $update,
            $step,
            $steps,
            sprintf('Schritt %d/%d abgeschlossen – %d Segmente erkannt', $step, $steps, $segmentCount)
        );

        $this->emitMonitoring('completed', [
            'segment_count' => $segmentCount,
            'day_count' => $dayCount,
            'away_day_count' => $metrics['away_day_count'],
            'timestamped_count' => $timestampedCount,
        ]);

        return $segments;
    }

    /**
     * @param callable(int $done, int $max, string $stage):void|null $update
     */
    private function notifyProgress(?callable $update, int $done, int $max, string $stage): void
    {
        if ($update === null) {
            return;
        }

        $update($done, $max, $stage);
    }

    /**
     * @param list<Media> $items
     *
     * @return list<Media>
     */
    private function sortChronologically(array $items): array
    {
        $ordered = $items;

        usort(
            $ordered,
            static function (Media $left, Media $right): int {
                $leftTakenAt  = $left->getTakenAt();
                $rightTakenAt = $right->getTakenAt();

                if ($leftTakenAt === null || $rightTakenAt === null) {
                    return $leftTakenAt <=> $rightTakenAt;
                }

                $comparison = $leftTakenAt <=> $rightTakenAt;
                if ($comparison !== 0) {
                    return $comparison;
                }

                return strcmp($left->getPath(), $right->getPath());
            }
        );

        return $ordered;
    }

    /**
     * @param array<string, array{
     *     travelKm: float,
     *     maxDistanceKm: float,
     *     baseAway: bool,
     *     isAwayCandidate: bool,
     * }> $days
     *
     * @return array{
     *     away_day_count: int,
     *     away_candidate_count: int,
     *     total_travel_km: float,
     *     max_distance_km: float,
     *     avg_travel_km: float,
     * }
     */
    private function summariseDays(array $days): array
    {
        $awayDayCount        = 0;
        $awayCandidateCount  = 0;
        $totalTravelKm       = 0.0;
        $maxDistanceKm       = 0.0;
        $dayCount            = count($days);

        foreach ($days as $day) {
            if ($day['baseAway'] === true) {
                ++$awayDayCount;
            }

            if ($day['isAwayCandidate'] === true) {
                ++$awayCandidateCount;
            }

            $totalTravelKm += $day['travelKm'];

            if ($day['maxDistanceKm'] > $maxDistanceKm) {
                $maxDistanceKm = $day['maxDistanceKm'];
            }
        }

        $avgTravelKm = $dayCount > 0 ? $totalTravelKm / $dayCount : 0.0;

        return [
            'away_day_count' => $awayDayCount,
            'away_candidate_count' => $awayCandidateCount,
            'total_travel_km' => $totalTravelKm,
            'max_distance_km' => $maxDistanceKm,
            'avg_travel_km' => $avgTravelKm,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emitMonitoring(string $status, array $context = []): void
    {
        $this->monitoringEmitter?->emit(self::MONITORING_JOB, $status, $context);
    }
}
