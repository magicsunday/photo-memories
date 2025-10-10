<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Selection;

use MagicSunday\Memories\Entity\Media;

/**
 * Value object representing the curated selection output together with telemetry.
 */
final class SelectionResult
{
    /**
     * @param list<Media> $members   curated media members in deterministic order
     * @param array<string, mixed> $telemetry diagnostic counters about the selection process
     */
    public function __construct(
        private readonly array $members,
        private readonly array $telemetry,
    ) {
    }

    /**
     * Returns the curated members in deterministic order.
     *
     * @return list<Media>
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Returns diagnostic counters for inspection and debugging.
     *
     * @return array<string, mixed>
     */
    public function getTelemetry(): array
    {
        return $this->telemetry;
    }
}
