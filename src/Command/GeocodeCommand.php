<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Geocoding\LocationCellIndex;
use MagicSunday\Memories\Service\Geocoding\MediaLocationLinker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'memories:geocode',
    description: 'Orte aus GPS-Daten ermitteln und speichern'
)]
final class GeocodeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaLocationLinker $linker,
        private readonly LocationCellIndex $cellIndex,
        private readonly int $delayMs = 1200 // be polite to Nominatim
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl zu verarbeitender Medien', null)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Alle Medien erneut geokodieren (auch bereits verknüpft)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, keine Änderungen speichern');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = $input->getOption('limit');
        $limitN = \is_string($limit) ? (int) $limit : null;
        $all    = (bool) $input->getOption('all');

        $io->title('🗺️  Orte ermitteln');

        $loaded = $this->cellIndex->warmUpFromDb();
        $io->writeln(\sprintf('🔎 %d bekannte Zellen vorab geladen.', $loaded));

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

        $count = \count($medias);
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

        $batchSize = 100; // größerer Batch ist OK
        foreach ($medias as $m) {
            $bar->setMessage('Rückwärtssuche');
            $loc = $this->linker->link($m, 'de');

            if ($loc !== null) {
                $linked++;
            }

            // nur schlafen, wenn wirklich ein Netz-Call stattfand
            if ($this->linker->consumeLastUsedNetwork() && $this->delayMs > 0) {
                $netCalls++;
                \usleep($this->delayMs * 1000);
            }

            $processed++;
            $bar->advance();

            if (($processed % $batchSize) === 0) {
                // kein clear(): wir halten Location-Cache und managed Entities intakt
                $this->em->flush();
            }
        }

        $this->em->flush();

        $bar->finish();

        $io->writeln('');
        $io->writeln('');
        $io->writeln(\sprintf('✅ %d Medien verarbeitet, %d Orte verknüpft, %d Netzabfragen.', $processed, $linked, $netCalls));

        if ($dryRun) {
            $io->writeln('Hinweis: Dry-Run – es wurden keine Änderungen gespeichert.');
        }

        return Command::SUCCESS;
    }
}
