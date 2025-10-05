<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use MagicSunday\Memories\Service\Geocoding\DefaultGeocodingWorkflow;
use MagicSunday\Memories\Service\Geocoding\GeocodeCommandOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function trim;

/**
 * Class GeocodeCommand.
 */
#[AsCommand(
    name: 'memories:geocode',
    description: 'Orte aus GPS-Daten ermitteln und speichern'
)]
final class GeocodeCommand extends Command
{
    public function __construct(
        private readonly DefaultGeocodingWorkflow $workflow,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl zu verarbeitender Medien')
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

        $options = new GeocodeCommandOptions(
            $dryRun,
            $limitN,
            $all,
            is_string($city) && trim($city) !== '' ? $city : null,
            $missingPois,
            $refreshPois,
        );

        $this->workflow->run($options, $io, $output);

        return Command::SUCCESS;
    }
}
