<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Class LocationRefreshSummary.
 */
final readonly class LocationRefreshSummary
{
    public function __construct(
        private int $processed,
        private int $metadataUpdated,
        private int $poisUpdated,
        private int $geocodeCalls,
        private int $poiNetworkCalls,
    ) {
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getMetadataUpdated(): int
    {
        return $this->metadataUpdated;
    }

    public function getPoisUpdated(): int
    {
        return $this->poisUpdated;
    }

    public function getGeocodeCalls(): int
    {
        return $this->geocodeCalls;
    }

    public function getPoiNetworkCalls(): int
    {
        return $this->poiNetworkCalls;
    }

    public function render(SymfonyStyle $io): void
    {
        $io->writeln(
            sprintf(
                '✅ %d Orte geprüft, %d Metadaten aktualisiert, %d POI-Anpassungen, %d Geocoding-Abfragen, %d POI-Netzaufrufe.',
                $this->processed,
                $this->metadataUpdated,
                $this->poisUpdated,
                $this->geocodeCalls,
                $this->poiNetworkCalls,
            ),
        );
    }
}
