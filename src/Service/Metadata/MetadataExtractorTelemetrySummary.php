<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

/**
 * Aggregated telemetry information for a single metadata extractor.
 */
final class MetadataExtractorTelemetrySummary
{
    private int $runs = 0;

    private int $failures = 0;

    private int $skips = 0;

    private float $totalDurationMs = 0.0;

    private ?string $lastErrorMessage = null;

    public function __construct(private readonly string $extractorClass)
    {
    }

    public function registerSuccess(?float $durationMs): void
    {
        $this->runs++;

        if ($durationMs !== null) {
            $this->totalDurationMs += $durationMs;
        }

        $this->lastErrorMessage = null;
    }

    public function registerFailure(?float $durationMs, string $errorMessage): void
    {
        $this->runs++;
        $this->failures++;

        if ($durationMs !== null) {
            $this->totalDurationMs += $durationMs;
        }

        $this->lastErrorMessage = $errorMessage;
    }

    public function registerSkip(): void
    {
        $this->skips++;
    }

    public function getExtractorClass(): string
    {
        return $this->extractorClass;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function getFailures(): int
    {
        return $this->failures;
    }

    public function getSkips(): int
    {
        return $this->skips;
    }

    public function getTotalDurationMs(): float
    {
        return $this->totalDurationMs;
    }

    public function getAverageDurationMs(): float
    {
        if ($this->runs === 0) {
            return 0.0;
        }

        return $this->totalDurationMs / $this->runs;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }
}
