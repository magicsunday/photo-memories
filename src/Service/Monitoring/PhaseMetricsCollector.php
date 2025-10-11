<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Monitoring;

use function ceil;
use function count;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function microtime;
use function sort;

/**
 * Aggregates counts, samples and durations for individual pipeline phases.
 */
final class PhaseMetricsCollector
{
    /** @var array<string, float> */
    private array $phaseStart = [];

    /** @var array<string, float> */
    private array $durationsMs = [];

    /** @var array<string, array<string, array<string, int|float>>> */
    private array $counts = [];

    /** @var array<string, array<string, list<float>>> */
    private array $samples = [];

    public function begin(string $phase): void
    {
        $this->phaseStart[$phase] = microtime(true);
    }

    public function end(string $phase): void
    {
        $startedAt = $this->phaseStart[$phase] ?? null;
        if ($startedAt === null) {
            return;
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1000.0;
        $this->durationsMs[$phase] = ($this->durationsMs[$phase] ?? 0.0) + $elapsedMs;

        unset($this->phaseStart[$phase]);
    }

    /**
     * Records scalar counters for the provided phase and metric group.
     *
     * @param array<string, int|float> $values
     */
    public function addCounts(string $phase, string $group, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_int($value) && !is_float($value)) {
                if (!is_numeric($value)) {
                    continue;
                }

                $value = (float) $value;
            }

            $this->counts[$phase][$group][$key] = is_float($value) ? $value : (int) $value;
        }
    }

    /**
     * Stores metric samples that should later be summarised.
     *
     * @param list<int|float> $values
     */
    public function addSamples(string $phase, string $metric, array $values): void
    {
        if ($values === []) {
            return;
        }

        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value)) {
                if (!is_numeric($value)) {
                    continue;
                }

                $value = (float) $value;
            }

            $this->samples[$phase][$metric][] = (float) $value;
        }
    }

    /**
     * Produces a consolidated summary payload ready for monitoring emission.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function summarise(array $context = []): array
    {
        $medians     = [];
        $percentiles = [];

        foreach ($this->samples as $phase => $metrics) {
            foreach ($metrics as $metric => $values) {
                if ($values === []) {
                    continue;
                }

                sort($values);
                $medians[$phase][$metric] = $this->median($values);
                $percentiles[$phase][$metric] = [
                    'p90' => $this->percentile($values, 0.90),
                    'p99' => $this->percentile($values, 0.99),
                ];
            }
        }

        return $context + [
            'phase_metrics' => [
                'counts'       => $this->counts,
                'medians'      => $medians,
                'percentiles'  => $percentiles,
                'durations_ms' => $this->durationsMs,
            ],
        ];
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $middle = (int) ($count / 2);
        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /**
     * @param list<float> $values
     */
    private function percentile(array $values, float $ratio): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) ceil($ratio * $count) - 1;
        if ($index < 0) {
            $index = 0;
        }

        if ($index >= $count) {
            $index = $count - 1;
        }

        return $values[$index];
    }
}
