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
use function is_array;
use function is_string;
use function strrpos;
use function substr;

/**
 * Configures metadata pipeline execution per extractor.
 */
final readonly class MetadataExtractorPipelineConfiguration
{
    /**
     * @param array<string, array{enabled?: bool, telemetry?: bool, reason?: string}> $stepConfigurations
     */
    public function __construct(
        private array $stepConfigurations,
        private bool $telemetryEnabledByDefault,
    ) {
    }

    public function isEnabled(SingleMetadataExtractorInterface $extractor): bool
    {
        $configuration = $this->stepConfigurations[$extractor::class] ?? null;

        if (is_array($configuration) && array_key_exists('enabled', $configuration)) {
            return $configuration['enabled'] === true;
        }

        return true;
    }

    public function shouldCollectTelemetry(SingleMetadataExtractorInterface $extractor): bool
    {
        $configuration = $this->stepConfigurations[$extractor::class] ?? null;

        if (is_array($configuration) && array_key_exists('telemetry', $configuration)) {
            return $configuration['telemetry'] === true;
        }

        return $this->telemetryEnabledByDefault;
    }

    public function disabledReason(SingleMetadataExtractorInterface $extractor): ?string
    {
        $configuration = $this->stepConfigurations[$extractor::class] ?? null;

        if (is_array($configuration) && array_key_exists('reason', $configuration)) {
            $reason = $configuration['reason'];

            if (is_string($reason) && $reason !== '') {
                return $reason;
            }
        }

        return null;
    }

    public function describeExtractor(SingleMetadataExtractorInterface $extractor): string
    {
        $class = $extractor::class;
        $separatorPosition = strrpos($class, '\\');

        if ($separatorPosition === false) {
            return $class;
        }

        return substr($class, $separatorPosition + 1);
    }
}
