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
use MagicSunday\Memories\Utility\MediaMath;

use function is_array;
use function is_numeric;
use function sprintf;

/**
 * Class AbstractTimeRangeClusterScoreHeuristic
 */
abstract class AbstractTimeRangeClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    public function __construct(
        private int $timeRangeMinSamples,
        private float $timeRangeMinCoverage,
        private int $minValidYear,
    ) {
    }

    /**
     * @param array<int, Media> $mediaMap
     *
     * @return array{from:int,to:int}|null
     */
    protected function ensureTimeRange(ClusterDraft $cluster, array $mediaMap): ?array
    {
        $params = $cluster->getParams();
        /** @var array{from:int,to:int}|null $range */
        $range = is_array($params['time_range'] ?? null) ? $params['time_range'] : null;

        if ($this->isValidTimeRange($range)) {
            return $range;
        }

        $computed = $this->computeTimeRangeFromMembers($cluster, $mediaMap);
        if ($computed !== null) {
            $cluster->setParam('time_range', $computed);
        }

        return $computed;
    }

    /**
     * @param array{from:int,to:int}|null $range
     *
     * @return bool
     * @throws \DateMalformedStringException
     */
    protected function isValidTimeRange(?array $range): bool
    {
        if (!is_array($range) || !isset($range['from'], $range['to'])) {
            return false;
        }

        $from = (int) $range['from'];
        $to   = (int) $range['to'];
        if ($from <= 0 || $to <= 0 || $to < $from) {
            return false;
        }

        $minTs = (new DateTimeImmutable(sprintf('%04d-01-01', $this->minValidYear)))->getTimestamp();

        return $from >= $minTs && $to >= $minTs;
    }

    /**
     * @param array<int, Media> $mediaMap
     *
     * @return array{from:int,to:int}|null
     */
    private function computeTimeRangeFromMembers(ClusterDraft $cluster, array $mediaMap): ?array
    {
        $items = $this->collectMediaItems($cluster, $mediaMap);
        if ($items === []) {
            return null;
        }

        return MediaMath::timeRangeReliable(
            $items,
            $this->timeRangeMinSamples,
            $this->timeRangeMinCoverage,
            $this->minValidYear
        );
    }

    protected function timeRangeFromParams(ClusterDraft $cluster): ?array
    {
        $params = $cluster->getParams();
        $range  = $params['time_range'] ?? null;
        if (!is_array($range)) {
            return null;
        }

        if (!is_numeric($range['from'] ?? null) || !is_numeric($range['to'] ?? null)) {
            return null;
        }

        return ['from' => (int) $range['from'], 'to' => (int) $range['to']];
    }
}
