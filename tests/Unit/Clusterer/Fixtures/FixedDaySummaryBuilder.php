<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Fixtures;

use MagicSunday\Memories\Clusterer\Contract\DaySummaryBuilderInterface;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;

/**
 * Day summary builder that returns a fixed set of summaries regardless of the input.
 *
 * @phpstan-import-type DaySummary from InitializationStage
 */
final readonly class FixedDaySummaryBuilder implements DaySummaryBuilderInterface
{
    /**
     * @param array<string, DaySummary> $days
     */
    public function __construct(private array $days)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function buildDaySummaries(array $items, array $home): array
    {
        return $this->days;
    }
}
