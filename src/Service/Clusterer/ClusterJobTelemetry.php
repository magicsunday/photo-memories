<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use function array_map;
use function max;

/**
 * Telemetry collected during a clustering job run.
 */
final readonly class ClusterJobTelemetry
{
    /**
     * @var array<string, int>
     */
    private array $stageCounts;

    /**
     * @var list<ClusterTelemetrySummary>
     */
    private array $topClusters;

    /**
     * @param array<string, int>            $stageCounts
     * @param list<ClusterTelemetrySummary> $topClusters
     */
    public function __construct(array $stageCounts, array $topClusters)
    {
        $this->stageCounts = array_map(
            static fn (int $value): int => max(0, $value),
            $stageCounts,
        );
        $this->topClusters = $topClusters;
    }

    /**
     * Returns the captured counts per processing stage.
     *
     * @return array<string, int>
     */
    public function getStageCounts(): array
    {
        return $this->stageCounts;
    }

    /**
     * Returns the captured count for a single processing stage.
     */
    public function getStageCount(string $stage): int
    {
        return $this->stageCounts[$stage] ?? 0;
    }

    /**
     * Returns the top cluster summaries extracted from the consolidated result.
     *
     * @return list<ClusterTelemetrySummary>
     */
    public function getTopClusters(): array
    {
        return $this->topClusters;
    }
}
