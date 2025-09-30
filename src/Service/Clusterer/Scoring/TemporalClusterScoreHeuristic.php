<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

use function count;

final class TemporalClusterScoreHeuristic extends AbstractTimeRangeClusterScoreHeuristic
{
    public function __construct(
        int $timeRangeMinSamples,
        float $timeRangeMinCoverage,
        int $minValidYear,
    ) {
        parent::__construct($timeRangeMinSamples, $timeRangeMinCoverage, $minValidYear);
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params    = $cluster->getParams();
        $timeRange = $this->ensureTimeRange($cluster, $mediaMap) ?? $this->timeRangeFromParams($cluster);
        $media     = $this->collectMediaItems($cluster, $mediaMap);
        $members   = count($cluster->getMembers());

        $cached = [
            'score'            => $this->floatOrNull($params['temporal_score'] ?? null),
            'coverage'         => $this->floatOrNull($params['temporal_coverage'] ?? null),
            'duration_seconds' => $this->intOrNull($params['temporal_duration_seconds'] ?? null),
        ];

        $metrics = $this->computeTemporalMetrics($media, $members, $timeRange, $cached);

        $cluster->setParam('temporal_score', $metrics['score']);
        $cluster->setParam('temporal_coverage', $metrics['coverage']);
        $cluster->setParam('temporal_duration_seconds', $metrics['duration_seconds']);
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['temporal_score'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'time_coverage';
    }

    /**
     * @param list<Media>                                                           $mediaItems
     * @param int                                                                   $members
     * @param array{from:int,to:int}|null                                           $timeRange
     * @param array{score:float|null,coverage:float|null,duration_seconds:int|null} $cached
     *
     * @return array{score:float,coverage:float,duration_seconds:int}
     */
    private function computeTemporalMetrics(array $mediaItems, int $members, ?array $timeRange, array $cached): array
    {
        $duration = $cached['duration_seconds'] ?? null;
        if ($duration === null && is_array($timeRange) && isset($timeRange['from'], $timeRange['to'])) {
            $duration = max(0, $timeRange['to'] - $timeRange['from']);
        }

        $duration = $duration !== null ? max(0, (int) $duration) : 0;

        $coverage = $cached['coverage'] ?? null;
        if ($coverage === null) {
            $timestamped = 0;
            if ($members > 0) {
                foreach ($mediaItems as $media) {
                    if ($media->getTakenAt() instanceof DateTimeImmutable) {
                        ++$timestamped;
                    }
                }

                $coverage = $members > 0 ? $timestamped / $members : 0.0;
            } else {
                $coverage = 0.0;
            }
        }

        $score = $cached['score'] ?? null;
        if ($score === null) {
            $spanScore = $duration > 0 ? $this->spanScore((float) $duration) : 0.0;
            $score     = $this->combineScores([
                [$coverage, 0.55],
                [$spanScore, 0.45],
            ], 0.0);
        }

        return [
            'score'            => $this->clamp01($score),
            'coverage'         => $this->clamp01($coverage),
            'duration_seconds' => $duration,
        ];
    }
}
