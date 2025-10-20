<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection;

use InvalidArgumentException;

/**
 * Collects rejection telemetry emitted by individual selection stages.
 */
final class SelectionTelemetry
{
    public const REASON_TIME_GAP = 'time_gap';
    public const REASON_PHASH = 'phash_similarity';
    public const REASON_STAYPOINT = 'staypoint_quota';
    public const REASON_ORIENTATION = 'orientation_balance';
    public const REASON_SCENE = 'scene_balance';
    public const REASON_PEOPLE = 'people_balance';
    public const REASON_DAY_QUOTA = 'day_quota';
    public const REASON_TIME_SLOT = 'time_slot';

    /**
     * Ordered rejection reasons tracked by the telemetry collector.
     *
     * @var list<string>
     */
    private const REASONS = [
        self::REASON_TIME_GAP,
        self::REASON_DAY_QUOTA,
        self::REASON_TIME_SLOT,
        self::REASON_STAYPOINT,
        self::REASON_PHASH,
        self::REASON_SCENE,
        self::REASON_ORIENTATION,
        self::REASON_PEOPLE,
    ];

    /**
     * @var array<string, int>
     */
    private array $reasonCounters = [];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $mmrSummary = null;

    public function __construct()
    {
        foreach (self::REASONS as $reason) {
            $this->reasonCounters[$reason] = 0;
        }
    }

    public function increment(string $reason): void
    {
        if (!isset($this->reasonCounters[$reason])) {
            throw new InvalidArgumentException('Unknown telemetry reason ' . $reason);
        }

        ++$this->reasonCounters[$reason];
    }

    /**
     * Returns the collected rejection counters keyed by reason.
     *
     * @return array<string, int>
     */
    public function reasonCounts(): array
    {
        return $this->reasonCounters;
    }

    /**
     * Records the outcome of the maximal marginal relevance re-ranking step.
     *
     * @param list<array<string, mixed>> $iterations
     * @param list<int>                  $selectedIds
     */
    public function recordMmrStep(
        float $lambda,
        float $similarityFloor,
        float $similarityCap,
        int $maxConsideration,
        int $poolSize,
        array $iterations,
        array $selectedIds,
    ): void {
        $this->mmrSummary = [
            'lambda' => $lambda,
            'similarity_floor' => $similarityFloor,
            'similarity_cap' => $similarityCap,
            'max_considered' => $maxConsideration,
            'pool_size' => $poolSize,
            'selected' => $selectedIds,
            'iterations' => $iterations,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mmrSummary(): ?array
    {
        return $this->mmrSummary;
    }
}
