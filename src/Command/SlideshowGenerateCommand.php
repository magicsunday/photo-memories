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
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoGeneratorInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function file_exists;
use function sprintf;
use function file_put_contents;
use function is_file;
use function unlink;

use const LOCK_EX;

/**
 * Console command that executes slideshow generation jobs.
 */
final class SlideshowGenerateCommand extends Command
{
    protected static $defaultName = 'slideshow:generate';

    protected static $defaultDescription = 'Generates a slideshow video for the given job file.';

    public function __construct(private readonly SlideshowVideoGeneratorInterface $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Absolute path to the job file.');
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
        file_put_contents(
            $job->errorPath(),
            $throwable->getMessage() ?: 'Unbekannter Fehler bei der Videoerstellung.',
            LOCK_EX
        );

        if (file_exists($job->lockPath())) {
            unlink($job->lockPath());
        }
    }
}
