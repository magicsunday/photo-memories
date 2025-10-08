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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interface PoiUpdateProcessorInterface.
 */
interface PoiUpdateProcessorInterface
{
    /**
     * @param iterable<Location> $locations
     */
    public function process(iterable $locations, bool $refreshPois, bool $dryRun, OutputInterface $output): PoiUpdateSummary;
}
