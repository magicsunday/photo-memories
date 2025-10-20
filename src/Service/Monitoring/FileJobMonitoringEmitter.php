<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Monitoring;

use DateTimeImmutable;
use JsonException;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use Psr\Clock\ClockInterface;
use Stringable;

use function array_key_exists;
use function array_merge;
use function dirname;
use function is_dir;
use function is_array;
use function is_string;
use function json_encode;
use function mkdir;
use function str_contains;
use function str_starts_with;
use function rtrim;
use function trim;

use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const PHP_EOL;

/**
 * Writes monitoring events as JSON lines to a log file.
 */
final class FileJobMonitoringEmitter implements JobMonitoringEmitterInterface
{
    public function __construct(
        private readonly string $logPath,
        private readonly bool $enabled = true,
        private readonly ?ClockInterface $clock = null,
        private readonly string $schemaVersion = '1.0',
    ) {
    }

    public function emit(Stringable|string|int|float|bool $job, Stringable|string|int|float|bool $status, array $context = []): void
    {
        if ($this->logPath === '' || !$this->enabled) {
            return;
        }

        $jobName    = $this->normaliseScalar($job);
        $statusName = $this->normaliseScalar($status);

        if ($jobName === '' || $statusName === '') {
            return;
        }

        $payload = $this->buildPayload($jobName, $statusName, $context);
        $json = $this->encodePayload($payload);

        if ($json === null) {
            return;
        }

        $this->ensureDirectory();

        file_put_contents($this->logPath, $json . PHP_EOL, LOCK_EX | FILE_APPEND);
    }

    private function normaliseScalar(Stringable|string|int|float|bool $value): string
    {
        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $job, string $status, array $context): array
    {
        $timestamp = $this->clock?->now() ?? new DateTimeImmutable();

        if (!array_key_exists('job', $context)) {
            $context = array_merge(['job' => $job], $context);
        }

        if (!array_key_exists('status', $context)) {
            $context['status'] = $status;
        }

        if (!array_key_exists('timestamp', $context)) {
            $context['timestamp'] = $timestamp->format(DateTimeImmutable::ATOM);
        }

        if (!array_key_exists('schema_version', $context)) {
            $context['schema_version'] = $this->schemaVersion;
        }

        $extra = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                continue;
            }

            $extra[$normalizedKey] = $value;
        }

        $metrics = $extra['phase_metrics'] ?? null;
        if (is_array($metrics)) {
            unset($extra['phase_metrics']);

            foreach ([
                'counts'       => 'phase_counts',
                'medians'      => 'phase_medians',
                'percentiles'  => 'phase_percentiles',
                'durations_ms' => 'phase_durations_ms',
            ] as $source => $target) {
                $values = $metrics[$source] ?? null;
                if (is_array($values)) {
                    $extra[$target] = $values;
                }
            }
        }

        return $this->enrichDecisionContext($extra);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function enrichDecisionContext(array $payload): array
    {
        $decisionFeatures = $payload['decision_features'] ?? [];
        $thresholds       = $payload['thresholds'] ?? [];
        $finalDecisions   = $payload['final_decisions'] ?? [];

        if (!is_array($decisionFeatures)) {
            $decisionFeatures = [];
        }

        if (!is_array($thresholds)) {
            $thresholds = [];
        }

        if (!is_array($finalDecisions)) {
            $finalDecisions = [];
        }

        $metaKeys = [
            'job' => true,
            'status' => true,
            'timestamp' => true,
            'schema_version' => true,
            'decision_features' => true,
            'thresholds' => true,
            'final_decisions' => true,
        ];

        foreach ($payload as $key => $value) {
            if (!is_string($key) || isset($metaKeys[$key])) {
                continue;
            }

            $lowerKey = strtolower($key);

            if ($this->isThresholdKey($lowerKey)) {
                $thresholds[$key] = $value;

                continue;
            }

            if ($this->isFinalDecisionKey($lowerKey)) {
                $finalDecisions[$key] = $value;

                continue;
            }

            $decisionFeatures[$key] = $value;
        }

        $payload['decision_features'] = $decisionFeatures;
        $payload['thresholds']       = $thresholds;
        $payload['final_decisions']  = $finalDecisions;

        return $payload;
    }

    private function isThresholdKey(string $key): bool
    {
        return str_contains($key, 'threshold')
            || str_contains($key, 'limit')
            || str_starts_with($key, 'min_')
            || str_starts_with($key, 'max_');
    }

    private function isFinalDecisionKey(string $key): bool
    {
        return str_contains($key, 'post')
            || str_contains($key, 'dropped')
            || str_contains($key, 'kept')
            || str_contains($key, 'selected')
            || str_contains($key, 'resolved')
            || str_contains($key, 'persisted')
            || str_contains($key, 'deleted');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): ?string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->logPath);
        if ($directory === '' || $directory === '.' || is_dir($directory)) {
            return;
        }

        $normalized = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($normalized === '') {
            return;
        }

        if (!is_dir($normalized)) {
            @mkdir($normalized, 0777, true);
        }
    }
}
