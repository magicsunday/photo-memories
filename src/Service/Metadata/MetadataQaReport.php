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
 * Immutable snapshot of QA findings collected during an index run.
 */
final readonly class MetadataQaReport
{
    /**
     * @param array<string,int>        $featureCounts
     * @param array<string,int>        $suggestionCounts
     * @param array<string,int>        $contradictionCounts
     * @param array<string,list<string>> $examples
     */
    public function __construct(
        private array $featureCounts,
        private array $suggestionCounts,
        private array $contradictionCounts,
        private array $examples,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->featureCounts === []
            && $this->suggestionCounts === []
            && $this->contradictionCounts === [];
    }

    /**
     * @return array<string,int>
     */
    public function getFeatureCounts(): array
    {
        return $this->featureCounts;
    }

    /**
     * @return array<string,int>
     */
    public function getSuggestionCounts(): array
    {
        return $this->suggestionCounts;
    }

    /**
     * @return array<string,int>
     */
    public function getContradictionCounts(): array
    {
        return $this->contradictionCounts;
    }

    /**
     * @return array<string,list<string>>
     */
    public function getExamples(): array
    {
        return $this->examples;
    }
}
