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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_string;
use function mb_strtolower;
use function sprintf;
use function trim;

/**
 * Class DefaultGeocodingWorkflow.
 */
final readonly class DefaultGeocodingWorkflow
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LocationCellIndex $cellIndex,
        private MediaGeocodingProcessorInterface $mediaProcessor,
        private PoiUpdateProcessorInterface $poiProcessor,
    ) {
    }

    public function run(GeocodeCommandOptions $options, SymfonyStyle $io, OutputInterface $output): void
    {
        $io->title('🗺️  Orte ermitteln');

        if ($options->updateMissingPois()) {
            $this->reprocessLocationsMissingPois($options, $io, $output);

            return;
        }

        $city = $options->getCity();
        if (is_string($city) && trim($city) !== '') {
            $this->reprocessLocationsByCity($city, $options, $io, $output);

            return;
        }

        if ($options->refreshPois() && !$options->refreshLocations()) {
            $this->refreshAllPois($options, $io, $output);

            return;
        }

        $this->processNewMedia($options, $io, $output);
    }

    private function processNewMedia(GeocodeCommandOptions $options, SymfonyStyle $io, OutputInterface $output): void
    {
        if ($options->refreshLocations()) {
            $io->note('Bestehende Ortsverknüpfungen werden vollständig aktualisiert.');
        } else {
            $loaded = $this->cellIndex->warmUpFromDb();
            $io->writeln(sprintf('🔎 %d bekannte Zellen vorab geladen.', $loaded));
        }

        $medias = $this->loadMedia($options);

        if (count($medias) < 1) {
            $io->writeln('Nichts zu tun – keine Medien mit GPS gefunden.');

            return;
        }

        $summary = $this->mediaProcessor->process($medias, $options->refreshPois(), $options->isDryRun(), $output);
        $summary->render($io);

        $locationsForPoi = $summary->getLocationsForPoiUpdate();
        if (count($locationsForPoi) > 0) {
            $io->section('📍 POI-Daten aktualisieren');
            if ($options->refreshPois()) {
                $io->note('Bestehende POI-Daten werden neu abgerufen.');
            }

            $poiSummary = $this->poiProcessor->process($locationsForPoi, $options->refreshPois(), $options->isDryRun(), $output);
            $poiSummary->render($io);
        }

        if ($options->isDryRun()) {
            $io->writeln('Hinweis: Dry-Run – es wurden keine Änderungen gespeichert.');
        }
    }

    private function refreshAllPois(GeocodeCommandOptions $options, SymfonyStyle $io, OutputInterface $output): void
    {
        $io->section('🌐 Alle POI-Daten aktualisieren');
        $io->note('Bestehende POI-Daten werden neu abgerufen.');

        $locations = $this->entityManager->getRepository(Location::class)
            ->createQueryBuilder('l')
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        if (count($locations) < 1) {
            $io->writeln('Keine Orte vorhanden.');

            return;
        }

        $summary = $this->poiProcessor->process($locations, true, $options->isDryRun(), $output);
        $summary->render($io);

        if ($options->isDryRun()) {
            $io->writeln('Hinweis: Dry-Run – es wurden keine Änderungen gespeichert.');
        }
    }

    private function reprocessLocationsMissingPois(GeocodeCommandOptions $options, SymfonyStyle $io, OutputInterface $output): void
    {
        $io->section('📍 Orte ohne POI-Daten ergänzen');

        if ($options->refreshPois()) {
            $io->note('Bestehende POI-Daten werden neu abgerufen.');
        }

        $qb = $this->entityManager->getRepository(Location::class)->createQueryBuilder('l');
        if (!$options->refreshPois()) {
            $qb->where('l.pois IS NULL');
        }

        $qb->orderBy('l.id', 'ASC');

        $locations = $qb->getQuery()->getResult();

        if (count($locations) < 1) {
            $io->writeln('Keine Orte ohne POI-Daten gefunden.');

            return;
        }

        $summary = $this->poiProcessor->process($locations, $options->refreshPois(), $options->isDryRun(), $output);
        $summary->render($io);

        if ($options->isDryRun()) {
            $io->writeln('Hinweis: Dry-Run – es wurden keine Änderungen gespeichert.');
        }
    }

    private function reprocessLocationsByCity(string $city, GeocodeCommandOptions $options, SymfonyStyle $io, OutputInterface $output): void
    {
        $normalizedCity = mb_strtolower($city);

        $io->section(sprintf('🏙️  Orte mit Stadtnamen "%s" aktualisieren', $city));

        if ($options->refreshPois()) {
            $io->note('Bestehende POI-Daten werden neu abgerufen.');
        }

        $qb = $this->entityManager->getRepository(Location::class)->createQueryBuilder('l');
        $qb->where('LOWER(l.city) = :city')
            ->orWhere('LOWER(l.displayName) LIKE :cityLike')
            ->setParameter('city', $normalizedCity)
            ->setParameter('cityLike', '%' . $normalizedCity . '%')
            ->orderBy('l.id', 'ASC');

        $locations = $qb->getQuery()->getResult();

        if (count($locations) < 1) {
            $io->writeln('Keine passenden Orte gefunden.');

            return;
        }

        $summary = $this->poiProcessor->process($locations, $options->refreshPois(), $options->isDryRun(), $output);
        $summary->render($io);

        if ($options->isDryRun()) {
            $io->writeln('Hinweis: Dry-Run – es wurden keine Änderungen gespeichert.');
        }
    }

    /**
     * @return list<Media>
     */
    private function loadMedia(GeocodeCommandOptions $options): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.gpsLat IS NOT NULL')
            ->andWhere('m.gpsLon IS NOT NULL')
            ->orderBy('m.geoCell8', 'ASC')
            ->addOrderBy('m.takenAt', 'ASC');

        if (!$options->refreshLocations()) {
            if ($options->refreshPois()) {
                $qb->andWhere('m.location IS NOT NULL');
            } else {
                $qb->andWhere('m.needsGeocode = true');
            }
        }

        /** @var list<Media> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
