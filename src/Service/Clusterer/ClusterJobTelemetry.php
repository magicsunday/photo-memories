<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

/**
 * Captures telemetry emitted by a cluster job run.
 */
final readonly class ClusterJobTelemetry
{
    public const STAGE_DRAFTS = 'drafts';

    public const STAGE_CONSOLIDATED = 'consolidated';

    /**
     * @param array<string, int>    $stageCounts
     * @param list<ClusterSummary> $topClusters
     * @param list<string>         $warnings
     */
    public function __construct(
        private array $stageCounts,
        private array $topClusters,
        private array $warnings = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param list<ClusterSummary> $topClusters
     * @param list<string>         $warnings
     */
    public static function fromStageCounts(int $drafts, int $consolidated, array $topClusters = [], array $warnings = []): self
    {
        return new self(
            [
                self::STAGE_DRAFTS => $drafts,
                self::STAGE_CONSOLIDATED => $consolidated,
            ],
            $topClusters,
            $warnings,
        );
    }

    /**
     * @return array<string, int>
     */
    public function getStageCounts(): array
    {
        return $this->stageCounts;
    }

    public function getStageCount(string $stage): ?int
    {
        return $this->stageCounts[$stage] ?? null;
    }

    /**
     * @return list<ClusterSummary>
     */
    public function getTopClusters(): array
    {
        return $this->topClusters;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
