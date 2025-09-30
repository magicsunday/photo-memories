<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

final class GeocodeCommandOptions
{
    public function __construct(
        private readonly bool $dryRun,
        private readonly ?int $limit,
        private readonly bool $processAll,
        private readonly ?string $city,
        private readonly bool $missingPois,
        private readonly bool $refreshPois,
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

    public function processAllMedia(): bool
    {
        return $this->processAll;
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
