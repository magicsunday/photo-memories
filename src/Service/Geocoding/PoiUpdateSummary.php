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

final class PoiUpdateSummary
{
    public function __construct(
        private readonly int $processed,
        private readonly int $updated,
        private readonly int $networkCalls,
    ) {
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getNetworkCalls(): int
    {
        return $this->networkCalls;
    }

    public function render(SymfonyStyle $io): void
    {
        $io->writeln(sprintf('✅ %d Orte verarbeitet, %d aktualisiert, %d Netzabfragen.', $this->processed, $this->updated, $this->networkCalls));
    }
}
