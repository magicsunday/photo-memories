<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;

/**
 * Value object describing the active member selection profile for a draft.
 *
 * @phpstan-type HomeDescriptor array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int}
 */
final class ClusterMemberSelectionProfile
{
    /**
     * @param HomeDescriptor $home
     */
    public function __construct(
        private readonly string $key,
        private readonly VacationSelectionOptions $options,
        private readonly array $home,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getOptions(): VacationSelectionOptions
    {
        return $this->options;
    }

    /**
     * @return HomeDescriptor
     */
    public function getHome(): array
    {
        return $this->home;
    }
}
