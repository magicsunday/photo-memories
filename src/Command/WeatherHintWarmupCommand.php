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
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Service\Weather\WeatherObservationStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'memories:weather:warmup',
    description: 'Wetterhinweise aus externen APIs abrufen und lokal cachen'
)]
final class WeatherHintWarmupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WeatherHintProviderInterface $provider,
        private readonly WeatherObservationStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl zu verarbeitender Medien')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bereits gespeicherte Hinweise erneut abrufen')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, keine Änderungen speichern');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $limit   = $input->getOption('limit');
        $limitN  = \is_string($limit) ? (int) $limit : null;
        $force   = (bool) $input->getOption('force');
        $dryRun  = (bool) $input->getOption('dry-run');

        $io->title('🌦️  Wetterhinweise vorbereiten');

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.gpsLat IS NOT NULL')
            ->andWhere('m.gpsLon IS NOT NULL')
            ->andWhere('m.takenAt IS NOT NULL')
            ->orderBy('m.takenAt', 'ASC');

        if ($limitN !== null && $limitN > 0) {
            $qb->setMaxResults($limitN);
        }

        /** @var list<Media> $medias */
        $medias = $qb->getQuery()->getResult();

        $count = \count($medias);
        if ($count < 1) {
            $io->writeln('Nichts zu tun – keine Medien mit GPS- und Zeitstempel gefunden.');
            return Command::SUCCESS;
        }

        $bar = new ProgressBar($output, $count);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | %message%');
        $bar->setMessage('Starte …');
        $bar->start();

        $processed   = 0;
        $skipped     = 0;
        $refetched   = 0;
        $failures    = 0;
        $storedHints = 0;

        foreach ($medias as $media) {
            $bar->setMessage('Prüfe Wetterdaten');

            $takenAt = $media->getTakenAt();
            $lat     = $media->getGpsLat();
            $lon     = $media->getGpsLon();

            if ($takenAt === null || $lat === null || $lon === null) {
                $processed++;
                $bar->advance();
                continue;
            }

            $timestamp = $takenAt->getTimestamp();

            $needsFetch = $force;
            if (!$needsFetch) {
                $needsFetch = !$this->storage->hasObservation($lat, $lon, $timestamp);
            }

            if (!$needsFetch) {
                $skipped++;
                $processed++;
                $bar->advance();
                continue;
            }

            if ($dryRun) {
                $refetched++;
                $processed++;
                $bar->advance();
                continue;
            }

            $bar->setMessage('Rufe API ab');
            $refetched++;
            $hint = $this->provider->getHint($media);

            if ($hint !== null) {
                $storedHints++;
            } else {
                $failures++;
            }

            $processed++;
            $bar->advance();

            if (($processed % 10) === 0) {
                $this->em->clear();
            }
        }

        $bar->finish();

        $io->writeln('');
        $io->writeln('');
        $io->writeln(\sprintf(
            '✅ %d Medien verarbeitet, %d Hinweise neu gespeichert, %d übersprungen, %d ohne Ergebnis.',
            $processed,
            $storedHints,
            $skipped,
            $failures
        ));

        if ($dryRun) {
            $io->note(\sprintf('%d Medien würden abgefragt werden.', $refetched));
        } else {
            $io->writeln(\sprintf('🌐 %d Medien über die Wetter-API abgefragt.', $refetched));

            if ($force) {
                $io->note('Option --force aktiv: vorhandene Daten wurden überschrieben.');
            }
        }

        return Command::SUCCESS;
    }
}
