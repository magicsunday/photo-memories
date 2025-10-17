<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Captures telemetry emitted by a cluster job run.
 */
final readonly class ClusterJobTelemetry
{
    public const STAGE_DRAFTS = 'drafts';

    public const STAGE_CONSOLIDATED = 'consolidated';

    /**
     * @param array<string, array{clusters:int,members_pre:int,members_post:int}> $stageStats
     * @param list<ClusterSummary>                                                $topClusters
     * @param list<string>                                                        $warnings
     */
    public function __construct(
        private array $stageStats,
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
        return self::fromStageStats(
            [
                self::STAGE_DRAFTS => [
                    'clusters'     => $drafts,
                    'members_pre'  => 0,
                    'members_post' => 0,
                ],
                self::STAGE_CONSOLIDATED => [
                    'clusters'     => $consolidated,
                    'members_pre'  => 0,
                    'members_post' => 0,
                ],
            ],
            $topClusters,
            $warnings,
        );
    }

    /**
     * @param array<string, array{clusters:int|float|string|null,members_pre:int|float|string|null,members_post:int|float|string|null}> $stageStats
     * @param list<ClusterSummary>                                                                                                      $topClusters
     * @param list<string>                                                                                                              $warnings
     */
    public static function fromStageStats(array $stageStats, array $topClusters = [], array $warnings = []): self
    {
        $normalized = [];
        foreach ($stageStats as $stage => $values) {
            if (!is_string($stage) || $stage === '' || !is_array($values)) {
                continue;
            }

            $clusters = $values['clusters'] ?? 0;
            $membersPre = $values['members_pre'] ?? 0;
            $membersPost = $values['members_post'] ?? 0;

            $normalized[$stage] = [
                'clusters'     => self::intValue($clusters),
                'members_pre'  => self::intValue($membersPre),
                'members_post' => self::intValue($membersPost),
            ];
        }

        return new self($normalized, $topClusters, $warnings);
    }

    /**
     * @return array<string, array{clusters:int,members_pre:int,members_post:int}>
     */
    public function getStageStats(): array
    {
        return $this->stageStats;
    }

    /**
     * @return array{clusters:int,members_pre:int,members_post:int}|null
     */
    public function getStageStat(string $stage): ?array
    {
        return $this->stageStats[$stage] ?? null;
    }

    /**
     * @return array<string, int>
     */
    public function getStageCounts(): array
    {
        $counts = [];
        foreach ($this->stageStats as $stage => $values) {
            $counts[$stage] = $values['clusters'];
        }

        return $counts;
    }

    public function getStageCount(string $stage): ?int
    {
        $stat = $this->stageStats[$stage] ?? null;

        return $stat === null ? null : $stat['clusters'];
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

    private static function intValue(int|float|string|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
