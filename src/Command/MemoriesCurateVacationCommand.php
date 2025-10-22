<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_array;

/**
 * Deprecated alias for running the curation pipeline for vacation memories.
 */
#[AsCommand(
    name: 'memories:curate-vacation',
    hidden: true,
    description: 'Alias für memories:curate --types=vacation (veraltet).'
)]
final class MemoriesCurateVacationCommand extends Command
{
    /**
     * Tracks whether the shared input definition has already been synchronised.
     */
    private bool $definitionInitialised = false;

    public function __construct(private readonly MemoriesCurateCommand $memoriesCurateCommand)
    {
        parent::__construct();

        $this->initialiseDefinition();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialiseDefinition();

        $io = new SymfonyStyle($input, $output);
        $io->warning(
            'Der Befehl "memories:curate-vacation" ist veraltet. Bitte verwende zukünftig '
            . '"memories:curate --types=vacation".'
        );

        $forwardInput = $this->createForwardInput($input);
        $forwardInput->setInteractive($input->isInteractive());

        return $this->memoriesCurateCommand->run($forwardInput, $output);
    }

    private function initialiseDefinition(): void
    {
        if ($this->definitionInitialised) {
            return;
        }

        $definition = clone $this->memoriesCurateCommand->getDefinition();
        $this->setDefinition($definition);

        $this->definitionInitialised = true;
    }

    private function createForwardInput(InputInterface $input): ArrayInput
    {
        $parameters = [];

        foreach ($input->getArguments() as $name => $value) {
            if ($name === 'command') {
                continue;
            }

            $parameters[$name] = $value;
        }

        foreach ($input->getOptions() as $name => $value) {
            if ($name === 'types') {
                continue;
            }

            if (!$this->shouldForwardOption($value)) {
                continue;
            }

            $parameters['--' . $name] = $value;
        }

        $parameters['--types'] = ['vacation'];

        return new ArrayInput($parameters, $this->memoriesCurateCommand->getDefinition());
    }

    private function shouldForwardOption(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if ($value === false) {
            return false;
        }

        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }
}
