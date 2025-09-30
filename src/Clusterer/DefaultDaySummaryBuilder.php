<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\DaySummaryBuilderInterface;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Entity\Media;

/**
 * Default implementation that prepares per-day vacation summaries.
 */
final class DefaultDaySummaryBuilder implements DaySummaryBuilderInterface
{
    /**
     * @param iterable<DaySummaryStageInterface> $stages
     */
    public function __construct(
        private readonly iterable $stages,
    ) {
    }

    /**
     * @param list<Media>                                                   $items
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     */
    public function buildDaySummaries(array $items, array $home): array
    {
        $days = $items;

        foreach ($this->stages as $stage) {
            $days = $stage->process($days, $home);

            if ($days === []) {
                break;
            }
        }

        return $days;
    }
}
