<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Entity\Location;
use Symfony\Component\Console\Style\SymfonyStyle;

final class GeocodingResultSummary
{
    /**
     * @param list<Location> $locationsForPoiUpdate
     */
    public function __construct(
        private readonly int $processed,
        private readonly int $linked,
        private readonly int $networkCalls,
        private readonly array $locationsForPoiUpdate,
    ) {
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getLinked(): int
    {
        return $this->linked;
    }

    public function getNetworkCalls(): int
    {
        return $this->networkCalls;
    }

    /**
     * @return list<Location>
     */
    public function getLocationsForPoiUpdate(): array
    {
        return $this->locationsForPoiUpdate;
    }

    public function render(SymfonyStyle $io): void
    {
        $io->writeln(sprintf('✅ %d Medien verarbeitet, %d Orte verknüpft, %d Netzabfragen.', $this->processed, $this->linked, $this->networkCalls));
    }
}
