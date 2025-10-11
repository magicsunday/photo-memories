<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection\Stage;

use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;

/**
 * Contract implemented by policy enforcement stages.
 */
interface SelectionStageInterface
{
    public function getName(): string;

    /**
     * Applies the stage specific filtering logic.
     *
     * @param list<array<string, mixed>> $candidates
     *
     * @return list<array<string, mixed>>
     */
    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array;
}
