<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Geocoding\LocationCellIndex;
use MagicSunday\Memories\Service\Geocoding\LocationResolver;
use MagicSunday\Memories\Service\Geocoding\MediaLocationLinker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_values;
use function count;
use function function_exists;
use function is_string;
use function mb_strimwidth;
use function mb_strtolower;
use function preg_replace;
use function sprintf;
use function strlen;
use function spl_object_id;
use function substr;
use function trim;
use function usleep;

#[AsCommand(
    name: 'memories:geocode',
    description: 'Orte aus GPS-Daten ermitteln und speichern'
)]
final class GeocodeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaLocationLinker $linker,
        private readonly LocationResolver $locationResolver,
        private readonly LocationCellIndex $cellIndex,
        private readonly int $delayMs = 1200, // be polite to Nominatim
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl zu verarbeitender Medien', null)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Alle Medien erneut geokodieren (auch bereits verknüpft)')
            ->addOption('city', null, InputOption::VALUE_REQUIRED, 'Orte nach Stadtnamen aktualisieren (z.B. "Paris")')
            ->addOption('missing-pois', null, InputOption::VALUE_NONE, 'Orte ohne POI-Daten ergänzen')
            ->addOption('refresh-pois', null, InputOption::VALUE_NONE, 'Bereits gespeicherte POI-Daten neu abrufen')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, keine Änderungen speichern');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $dryRun      = (bool) $input->getOption('dry-run');
        $limit       = $input->getOption('limit');
        $limitN      = is_string($limit) ? (int) $limit : null;
        $all         = (bool) $input->getOption('all');
        $city        = $input->getOption('city');
        $missingPois = (bool) $input->getOption('missing-pois');
        $refreshPois = (bool) $input->getOption('refresh-pois');

        if ($refreshPois) {
            $all = true;
        }

        $io->title('🗺️  Orte ermitteln');

        if ($missingPois) {
            return $this->reprocessLocationsMissingPois($dryRun, $refreshPois, $io, $output);
        }

        if (is_string($city) && $city !== '') {
            return $this->reprocessLocationsByCity($city, $dryRun, $refreshPois, $io, $output);
        }

        if ($refreshPois && !$all && $limitN === null) {
            return $this->refreshAllPois($dryRun, $io, $output);
        }

        $loaded = $this->cellIndex->warmUpFromDb();
        $io->writeln(sprintf('🔎 %d bekannte Zellen vorab geladen.', $loaded));

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.gpsLat IS NOT NULL')
            ->andWhere('m.gpsLon IS NOT NULL');

        if (!$all) {
            $qb->andWhere('m.location IS NULL');
        }

        $qb->orderBy('m.takenAt', 'ASC');

        if ($limitN !== null && $limitN > 0) {
            $qb->setMaxResults($limitN);
        }

        /** @var list<Media> $medias */
        $medias = $qb->getQuery()->getResult();

        $count = count($medias);
        if ($count < 1) {
            $io->writeln('Nichts zu tun – keine Medien mit GPS gefunden.');

            return Command::SUCCESS;
        }

        $bar = new ProgressBar($output, $count);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | %message%');
        $bar->setMessage('Starte …');
        $bar->start();

        $processed = 0;
        $linked    = 0;
        $netCalls  = 0;
        /** @var array<int,Location> $uniqueLocations */
        $uniqueLocations = [];

        $batchSize = 10; // kleinerer Batch verringert Datenverlust bei Fehlern
        foreach ($medias as $m) {
            $bar->setMessage('Rückwärtssuche');
            $loc = $this->linker->link($m, 'de', $refreshPois);

            if ($loc instanceof Location) {
                ++$linked;
                $uniqueLocations[spl_object_id($loc)] = $loc;
            }

            // nur schlafen, wenn wirklich ein Netz-Call stattfand
            $networkCalls = $this->linker->consumeLastNetworkCalls();
            if ($networkCalls > 0) {
                $netCalls += $networkCalls;

                if ($this->delayMs > 0) {
                    for ($i = 0; $i < $networkCalls; ++$i) {
                        usleep($this->delayMs * 1000);
                    }
                }
            }

            ++$processed;
            $bar->advance();

            if (($processed % $batchSize) === 0) {
                // kein clear(): wir halten Location-Cache und managed Entities intakt
                if (!$dryRun) {
                    $this->em->flush();
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $bar->finish();

        $io->writeln('');
        $io->writeln('');
        $io->writeln(sprintf('✅ %d Medien verarbeitet, %d Orte verknüpft, %d Netzabfragen.', $processed, $linked, $netCalls));

        $locationsForPois = array_values(array_filter(
            $uniqueLocations,
            static fn (Location $location): bool => $refreshPois || $location->getPois() === null,
        ));

        if (count($locationsForPois) > 0) {
            $io->section('📍 POI-Daten aktualisieren');
            if ($refreshPois) {
                $io->note('Bestehende POI-Daten werden neu abgerufen.');
            }

            $statistics = $this->processPoiUpdates($locationsForPois, $dryRun, $refreshPois, $output);

            $this->renderPoiUpdateSummary($io, $statistics, $dryRun);
        }

        if ($dryRun) {
            $io->writeln('Hinweis: Dry-Run – es wurden keine Änderungen gespeichert.');
        }

        return Command::SUCCESS;
    }

    private function refreshAllPois(bool $dryRun, SymfonyStyle $io, OutputInterface $output): int
    {
        $io->section('🌐 Alle POI-Daten aktualisieren');
        $io->note('Bestehende POI-Daten werden neu abgerufen.');

        $repo      = $this->em->getRepository(Location::class);
        $locations = $repo->createQueryBuilder('l')
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        if (count($locations) < 1) {
            $io->writeln('Keine Orte vorhanden.');

            return Command::SUCCESS;
        }

        $statistics = $this->processPoiUpdates($locations, $dryRun, true, $output);

        $this->renderPoiUpdateSummary($io, $statistics, $dryRun);

        return Command::SUCCESS;
    }

    private function reprocessLocationsMissingPois(bool $dryRun, bool $refreshPois, SymfonyStyle $io, OutputInterface $output): int
    {
        $io->section('📍 Orte ohne POI-Daten ergänzen');

        if ($refreshPois) {
            $io->note('Bestehende POI-Daten werden neu abgerufen.');
        }

        $repo = $this->em->getRepository(Location::class);
        $qb   = $repo->createQueryBuilder('l');

        if (!$refreshPois) {
            $qb->where('l.pois IS NULL');
        }

        $qb->orderBy('l.id', 'ASC');

        /** @var list<Location> $locations */
        $locations = $qb->getQuery()->getResult();

        $count = count($locations);
        if ($count < 1) {
            $io->writeln('Keine Orte ohne POI-Daten gefunden.');

            return Command::SUCCESS;
        }

        $statistics = $this->processPoiUpdates($locations, $dryRun, $refreshPois, $output);

        $this->renderPoiUpdateSummary($io, $statistics, $dryRun);

        return Command::SUCCESS;
    }

    private function reprocessLocationsByCity(string $city, bool $dryRun, bool $refreshPois, SymfonyStyle $io, OutputInterface $output): int
    {
        $normalizedCity = mb_strtolower($city);

        $io->section(sprintf('🏙️  Orte mit Stadtnamen "%s" aktualisieren', $city));

        if ($refreshPois) {
            $io->note('Bestehende POI-Daten werden neu abgerufen.');
        }

        $repo = $this->em->getRepository(Location::class);
        $qb   = $repo->createQueryBuilder('l');

        $qb->where('LOWER(l.city) = :city')
            ->orWhere('LOWER(l.displayName) LIKE :cityLike')
            ->setParameter('city', $normalizedCity)
            ->setParameter('cityLike', '%' . $normalizedCity . '%')
            ->orderBy('l.id', 'ASC');

        /** @var list<Location> $locations */
        $locations = $qb->getQuery()->getResult();

        $count = count($locations);
        if ($count < 1) {
            $io->writeln('Keine passenden Orte gefunden.');

            return Command::SUCCESS;
        }

        $statistics = $this->processPoiUpdates($locations, $dryRun, $refreshPois, $output);

        $this->renderPoiUpdateSummary($io, $statistics, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * @param list<Location> $locations
     *
     * @return array{int,int,int} Tuple of processed, updated, and network calls
     */
    private function processPoiUpdates(array $locations, bool $dryRun, bool $refreshPois, OutputInterface $output): array
    {
        $count = count($locations);

        $bar = new ProgressBar($output, $count);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | %message%');
        $bar->setMessage('Starte …');
        $bar->start();

        $processed = 0;
        $updated   = 0;
        $netCalls  = 0;
        $batchSize = 10;

        foreach ($locations as $location) {
            $label = $location->getDisplayName() ?? $location->getCity() ?? 'Unbenannter Ort';
            $bar->setMessage($this->formatProgressLabel($label));

            $beforePois = $location->getPois();

            $this->locationResolver->ensurePois($location, $refreshPois);
            if ($this->locationResolver->consumeLastUsedNetwork()) {
                ++$netCalls;
            }

            if ($beforePois !== $location->getPois()) {
                ++$updated;
            }

            ++$processed;
            $bar->advance();

            if ($processed % $batchSize === 0 && !$dryRun) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $bar->finish();

        $output->writeln('');
        $output->writeln('');

        return [$processed, $updated, $netCalls];
    }

    /**
     * @param array{int,int,int} $statistics
     */
    private function renderPoiUpdateSummary(SymfonyStyle $io, array $statistics, bool $dryRun): void
    {
        [$processed, $updated, $netCalls] = $statistics;

        $io->writeln(sprintf('✅ %d Orte verarbeitet, %d aktualisiert, %d Netzabfragen.', $processed, $updated, $netCalls));

        if ($dryRun) {
            $io->writeln('Hinweis: Dry-Run – es wurden keine Änderungen gespeichert.');
        }
    }

    /**
     * Shortens long progress bar labels to keep the output on a single line.
     */
    private function formatProgressLabel(string $label): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($label)) ?? $label;

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($normalized, 0, 70, '…', 'UTF-8');
        }

        return strlen($normalized) > 70
            ? substr($normalized, 0, 69) . '…'
            : $normalized;
    }
}
