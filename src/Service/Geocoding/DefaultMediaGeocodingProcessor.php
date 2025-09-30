<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function is_array;
use function iterator_to_array;
use function spl_object_id;
use function usleep;

final class DefaultMediaGeocodingProcessor implements MediaGeocodingProcessorInterface
{
    /**
     * @param positive-int $batchSize
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaLocationLinkerInterface $linker,
        private readonly string $locale,
        private readonly int $delayMs = 1200,
        private readonly int $batchSize = 10,
    ) {
    }

    public function process(iterable $media, bool $refreshPois, bool $dryRun, OutputInterface $output): GeocodingResultSummary
    {
        $medias = $this->normalizeIterable($media);
        $count  = count($medias);

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | %message%');
        $progressBar->setMessage('Starte …');
        $progressBar->start();

        $processed       = 0;
        $linked          = 0;
        $networkCalls    = 0;
        /** @var array<int,Location> $uniqueLocations */
        $uniqueLocations = [];

        foreach ($medias as $item) {
            $progressBar->setMessage('Rückwärtssuche');
            $location = $this->linker->link($item, $this->locale, $refreshPois);

            if ($location instanceof Location) {
                ++$linked;
                $uniqueLocations[spl_object_id($location)] = $location;
            }

            $networkCallsForMedia = $this->linker->consumeLastNetworkCalls();
            if ($networkCallsForMedia > 0) {
                $networkCalls += $networkCallsForMedia;

                if ($this->delayMs > 0) {
                    for ($i = 0; $i < $networkCallsForMedia; ++$i) {
                        usleep($this->delayMs * 1000);
                    }
                }
            }

            ++$processed;
            $progressBar->advance();

            if (($processed % $this->batchSize) === 0 && !$dryRun) {
                $this->entityManager->flush();
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $progressBar->finish();

        $output->writeln('');
        $output->writeln('');

        $locationsForPoiUpdate = $this->filterLocationsForPoiUpdate($uniqueLocations, $refreshPois);

        return new GeocodingResultSummary($processed, $linked, $networkCalls, $locationsForPoiUpdate);
    }

    /**
     * @param iterable<Media> $media
     *
     * @return list<Media>
     */
    private function normalizeIterable(iterable $media): array
    {
        if (is_array($media)) {
            return $media;
        }

        return iterator_to_array($media, false);
    }

    /**
     * @param array<int,Location> $uniqueLocations
     *
     * @return list<Location>
     */
    private function filterLocationsForPoiUpdate(array $uniqueLocations, bool $refreshPois): array
    {
        $locations = [];

        foreach ($uniqueLocations as $location) {
            if ($refreshPois || $location->getPois() === null) {
                $locations[] = $location;
            }
        }

        return $locations;
    }
}
