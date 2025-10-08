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

use function array_key_exists;
use function array_merge;
use function dirname;
use function is_dir;
use function is_string;
use function json_encode;
use function mkdir;
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
    ) {
    }

    public function emit(string $job, string $status, array $context = []): void
    {
        if ($this->logPath === '' || !$this->enabled) {
            return;
        }

        $job = trim($job);
        $status = trim($status);

        if ($job === '' || $status === '') {
            return;
        }

        $payload = $this->buildPayload($job, $status, $context);
        $json = $this->encodePayload($payload);

        if ($json === null) {
            return;
        }

        $this->ensureDirectory();

        file_put_contents($this->logPath, $json . PHP_EOL, LOCK_EX | FILE_APPEND);
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

        return $extra;
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
