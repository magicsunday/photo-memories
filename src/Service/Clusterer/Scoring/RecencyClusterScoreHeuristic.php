<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;

use function max;
use function min;
use function time;

/**
 * Class RecencyClusterScoreHeuristic
 */
final class RecencyClusterScoreHeuristic extends AbstractTimeRangeClusterScoreHeuristic
{
    /** @var callable():int */
    private $timeProvider;

    private int $now = 0;

    public function __construct(
        int $timeRangeMinSamples,
        float $timeRangeMinCoverage,
        int $minValidYear,
        ?callable $timeProvider = null,
    ) {
        parent::__construct($timeRangeMinSamples, $timeRangeMinCoverage, $minValidYear);
        $this->timeProvider = $timeProvider ?? static fn (): int => time();
    }

    public function prepare(array $clusters, array $mediaMap): void
    {
        $this->now = ($this->timeProvider)();
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params    = $cluster->getParams();
        $timeRange = $this->ensureTimeRange($cluster, $mediaMap);
        $recency   = $this->floatOrNull($params['recency'] ?? null) ?? 0.0;

        if ($timeRange !== null && !isset($params['recency'])) {
            $ageDays = max(0.0, ($this->now - (int) $timeRange['to']) / 86400.0);
            $recency = max(0.0, 1.0 - min(1.0, $ageDays / 365.0));
        }

        if ($timeRange !== null || isset($params['recency'])) {
            $cluster->setParam('recency', $recency);
        }
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['recency'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'recency';
    }
}
