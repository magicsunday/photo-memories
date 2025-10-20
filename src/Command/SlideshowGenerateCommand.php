<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use MagicSunday\Memories\Service\Slideshow\SlideshowJob;
use MagicSunday\Memories\Service\Slideshow\SlideshowStoryboardWriter;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoGeneratorInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function file_exists;
use function file_put_contents;
use function is_file;
use function sprintf;
use function unlink;

use const LOCK_EX;

/**
 * Console command that executes slideshow generation jobs.
 */
#[AsCommand(name: 'slideshow:generate', description: 'Erstellt ein Slideshow-Video für die angegebene Job-Datei.')]
final class SlideshowGenerateCommand extends Command
{
    public function __construct(
        private readonly SlideshowVideoGeneratorInterface $generator,
        private readonly SlideshowStoryboardWriter $storyboardWriter,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Absolute path to the job file.');
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Erstellt nur das Storyboard und rendert kein Video.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $jobFile = (string) $input->getArgument('job');

        try {
            $job = SlideshowJob::fromJsonFile($jobFile);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $storyboardPath = $this->storyboardWriter->write($job);
            $this->cleanup($job);
            if (is_file($job->jobFile())) {
                unlink($job->jobFile());
            }

            $io->success(sprintf('Storyboard für "%s" wurde geschrieben: %s', $job->id(), $storyboardPath));

            return Command::SUCCESS;
        }

        try {
            $this->generator->generate($job);
            $io->success(sprintf('Slideshow "%s" wurde erstellt.', $job->id()));
            $this->cleanup($job);

            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $this->writeError($job, $throwable);
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        } finally {
            if (is_file($job->jobFile())) {
                unlink($job->jobFile());
            }
        }
    }

    private function cleanup(SlideshowJob $job): void
    {
        if (file_exists($job->lockPath())) {
            unlink($job->lockPath());
        }

        if (is_file($job->errorPath())) {
            unlink($job->errorPath());
        }
    }

    private function writeError(SlideshowJob $job, Throwable $throwable): void
    {
        $message = $throwable->getMessage();
        if ($message === '') {
            $message = 'Unbekannter Fehler bei der Videoerstellung.';
        }

        file_put_contents(
            $job->errorPath(),
            $message,
            LOCK_EX
        );

        if (file_exists($job->lockPath())) {
            unlink($job->lockPath());
        }
    }
}
