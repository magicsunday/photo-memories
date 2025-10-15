<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use Closure;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressHandleInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressReporterInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function floor;
use function max;
use function microtime;
use function sprintf;

/**
 * Class ConsoleProgressReporter.
 */
final readonly class ConsoleProgressReporter implements ProgressReporterInterface
{
    public function __construct(
        private SymfonyStyle $io,
        private OutputInterface $output,
    ) {
    }

    public function getStyle(): SymfonyStyle
    {
        return $this->io;
    }

    public function create(string $sectionTitle, string $headline, int $max): ProgressHandleInterface
    {
        return $this->createHandle($sectionTitle, $headline, $max);
    }

    private function createHandle(string $sectionTitle, string $headline, int $max): ProgressHandleInterface
    {
        $this->io->section($sectionTitle);

        $section = $this->output->section();
        $bar     = $this->makeBar($section, $max, $headline);

        $factory = function (string $childSectionTitle, string $childHeadline, int $childMax): ProgressHandleInterface {
            return $this->createHandle($childSectionTitle, $childHeadline, $childMax);
        };

        return new ConsoleProgressHandle($section, $bar, $factory);
    }

    private function makeBar(ConsoleSectionOutput $section, int $max, string $headline): ProgressBar
    {
        $bar = new ProgressBar($section, $max);

        $startedAt = microtime(true);
        ProgressBar::setPlaceholderFormatterDefinition('duration_hms', static function () use ($startedAt): string {
            $elapsed = (int) max(0, microtime(true) - $startedAt);
            $hours   = (int) floor($elapsed / 3600);
            $minutes = (int) floor(($elapsed % 3600) / 60);
            $seconds = $elapsed % 60;

            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        });

        $bar->setFormat(sprintf(
            "%s\n%%current%%/%%max%% [%%bar%%] %%percent%%%% | Dauer: %%duration_hms%% | ETA: %%remaining%% | %%phase%% | Phase: %%detail%% | %%rate%%",
            $headline,
        ));
        $bar->setBarCharacter('=');
        $bar->setEmptyBarCharacter(' ');
        $bar->setProgressCharacter('>');
        $bar->setRedrawFrequency(1);
        $bar->setMessage('', 'phase');
        $bar->setMessage('–', 'detail');
        $bar->setMessage('–', 'rate');

        return $bar;
    }
}

/** @internal */
final class ConsoleProgressHandle implements ProgressHandleInterface
{
    /**
     * @param callable(string,string,int):ProgressHandleInterface $factory
     */
    public function __construct(
        private ConsoleSectionOutput $section,
        private ProgressBar $bar,
        private Closure $factory,
    ) {
    }

    public function advance(int $step = 1): void
    {
        if ($step <= 0) {
            return;
        }

        $this->bar->advance($step);
    }

    public function setPhase(?string $message): void
    {
        $this->bar->setMessage($message ?? '', 'phase');
        $this->bar->display();
    }

    public function setDetail(?string $message): void
    {
        $this->bar->setMessage($message ?? '–', 'detail');
        $this->bar->display();
    }

    public function setRate(?string $message): void
    {
        $this->bar->setMessage($message ?? '–', 'rate');
        $this->bar->display();
    }

    public function setProgress(int $current): void
    {
        $maxSteps = $this->bar->getMaxSteps();
        if ($current > $maxSteps) {
            $this->bar->setMaxSteps($current);
        }

        $this->bar->setProgress($current);
    }

    public function setMax(int $max): void
    {
        $this->bar->setMaxSteps(max(0, $max));
        $this->bar->display();
    }

    public function createChildHandle(string $sectionTitle, string $headline, int $max): ProgressHandleInterface
    {
        return ($this->factory)($sectionTitle, $headline, $max);
    }

    public function finish(): void
    {
        $this->bar->finish();
        $this->section->writeln('');
    }
}
