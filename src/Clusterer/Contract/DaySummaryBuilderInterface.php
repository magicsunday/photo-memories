<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Entity\Media;

/**
 * Builds per-day statistics used to assemble vacation segments.
 *
 * @phpstan-import-type DaySummary from InitializationStage
 */
interface DaySummaryBuilderInterface
{
    /**
     * @param list<Media>                                                                             $items
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     *
     * @return array<string, DaySummary>
     */
    public function buildDaySummaries(array $items, array $home): array;
}
