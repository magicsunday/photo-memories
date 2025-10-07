<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use function array_unique;
use function array_values;
use function implode;

/**
 * Value object describing the outcome of metadata QA checks.
 */
final readonly class MetadataQaInspectionResult
{
    /**
     * @param list<string> $missingFeatures
     * @param list<string> $suggestions
     */
    private function __construct(
        private array $missingFeatures,
        private array $suggestions,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * @param list<string> $missingFeatures
     * @param list<string> $suggestions
     */
    public static function withIssues(array $missingFeatures, array $suggestions): self
    {
        return new self(array_values(array_unique($missingFeatures)), array_values(array_unique($suggestions)));
    }

    public function hasIssues(): bool
    {
        return $this->missingFeatures !== [];
    }

    /**
     * @return list<string>
     */
    public function getMissingFeatures(): array
    {
        return $this->missingFeatures;
    }

    /**
     * @return list<string>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    public function toLogMessage(): ?string
    {
        if (!$this->hasIssues()) {
            return null;
        }

        $message = 'Warnung: fehlende Zeit-Features (' . implode(', ', $this->missingFeatures) . ').';
        if ($this->suggestions !== []) {
            $message .= ' Empfehlung: ' . implode('; ', $this->suggestions) . '.';
        }

        return $message;
    }
}
