<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use function array_key_exists;

/**
 * Collects execution telemetry for metadata extractors.
 */
final class MetadataExtractorTelemetry
{
    /**
     * @var array<string, MetadataExtractorTelemetrySummary>
     */
    private array $summaries = [];

    public function recordSuccess(string $extractorClass, ?float $durationMs): void
    {
        $this->summary($extractorClass)->registerSuccess($durationMs);
    }

    public function recordFailure(string $extractorClass, ?float $durationMs, string $errorMessage): void
    {
        $this->summary($extractorClass)->registerFailure($durationMs, $errorMessage);
    }

    public function recordSkip(string $extractorClass): void
    {
        $this->summary($extractorClass)->registerSkip();
    }

    /**
     * @return array<string, MetadataExtractorTelemetrySummary>
     */
    public function all(): array
    {
        return $this->summaries;
    }

    public function get(string $extractorClass): ?MetadataExtractorTelemetrySummary
    {
        return $this->summaries[$extractorClass] ?? null;
    }

    private function summary(string $extractorClass): MetadataExtractorTelemetrySummary
    {
        if (array_key_exists($extractorClass, $this->summaries) === false) {
            $this->summaries[$extractorClass] = new MetadataExtractorTelemetrySummary($extractorClass);
        }

        return $this->summaries[$extractorClass];
    }
}
