<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interface MediaGeocodingProcessorInterface.
 */
interface MediaGeocodingProcessorInterface
{
    /**
     * @param iterable<Media> $media
     */
    public function process(
        iterable $media,
        bool $refreshPois,
        bool $forceRefreshLocations,
        bool $dryRun,
        OutputInterface $output,
    ): GeocodingResultSummary;
}
