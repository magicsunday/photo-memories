<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Title;

/**
 * Immutable data transfer object describing a summarised travel route.
 */
final readonly class RouteSummary
{
    /**
     * @param list<string> $stops
     */
    public function __construct(
        public string $routeLabel,
        public int $stopCount,
        public int $legCount,
        public float $distanceKm,
        public string $distanceLabel,
        public string $stopLabel,
        public string $legLabel,
        public string $metricsLabel,
        public array $stops,
    ) {
    }
}
