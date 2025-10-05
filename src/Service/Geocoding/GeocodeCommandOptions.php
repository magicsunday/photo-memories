<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

/**
 * Class GeocodeCommandOptions.
 */
final readonly class GeocodeCommandOptions
{
    public function __construct(
        private bool $dryRun,
        private ?int $limit,
        private bool $refreshLocations,
        private ?string $city,
        private bool $missingPois,
        private bool $refreshPois,
    ) {
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function refreshLocations(): bool
    {
        return $this->refreshLocations;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function updateMissingPois(): bool
    {
        return $this->missingPois;
    }

    public function refreshPois(): bool
    {
        return $this->refreshPois;
    }
}
