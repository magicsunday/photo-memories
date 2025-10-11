<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection;

/**
 * Encapsulates the curated member identifiers alongside diagnostic telemetry.
 */
final class MemberSelectionResult
{
    /**
     * @param list<int>               $memberIds curated member identifiers in deterministic order
     * @param array<string, mixed>    $telemetry diagnostic counters collected during curation
     */
    public function __construct(
        private readonly array $memberIds,
        private readonly array $telemetry,
    ) {
    }

    /**
     * Returns curated member identifiers in deterministic order.
     *
     * @return list<int>
     */
    public function getMemberIds(): array
    {
        return $this->memberIds;
    }

    /**
     * Returns diagnostic counters captured during the curation run.
     *
     * @return array<string, mixed>
     */
    public function getTelemetry(): array
    {
        return $this->telemetry;
    }
}
