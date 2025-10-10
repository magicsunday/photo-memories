<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Selection;

/**
 * Defines the contract for member selectors that curate day summaries into a
 * high quality media subset.
 *
 * @phpstan-import-type DaySummary from \MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage
 * @phpstan-type HomeDescriptor array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int}
 */
interface MemberSelectorInterface
{
    /**
     * Selects a curated list of media for the provided day summaries.
     *
     * @param array<string, DaySummary> $daySummaries indexed by ISO date (Y-m-d)
     * @param HomeDescriptor            $home          descriptor of the primary home location
     */
    public function select(array $daySummaries, array $home, VacationSelectionOptions $options): SelectionResult;
}
