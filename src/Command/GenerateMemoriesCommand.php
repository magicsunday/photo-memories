<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use MagicSunday\Memories\Generator\MemoryGenerator;
use MagicSunday\Memories\Model\MediaItem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_slice;
use function count;
use function sprintf;

/**
 * Class GenerateMemoriesCommand.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-memories/
 */
#[AsCommand(
    name: 'memories:generate',
    description: 'Erzeuge Rückblicke aus einem Medien-Ordner'
)]
class GenerateMemoriesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'directory',
                InputArgument::REQUIRED,
                'Pfad zum Medien-Ordner'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Ausgabe als JSON'
            )
            ->addOption(
                'top',
                null,
                InputOption::VALUE_REQUIRED,
                'Zeige nur die Top-N Medien pro Rückblick'
            )
            ->addOption(
                'summary-only',
                null,
                InputOption::VALUE_NONE,
                'Nur Zusammenfassungen ausgeben'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonOutput  = (bool) $input->getOption('json');
        $summaryOnly = (bool) $input->getOption('summary-only');
        $topN        = $input->getOption('top') !== null ? (int) $input->getOption('top') : null;

        $srcDir   = $input->getArgument('directory');
        $realpath = realpath($srcDir);
        $srcDir   = $realpath !== false ? $realpath : $srcDir;

        if (!is_dir($srcDir)) {
            $output->writeln(sprintf('<error>Verzeichnis nicht gefunden: %s</error>', $srcDir));

            return Command::FAILURE;
        }

        $gen = new MemoryGenerator(__DIR__ . '/../config/Presets.yaml');
        $output->writeln(
            sprintf('📸 Scanne Medien in: %s ...', $srcDir));
        $items = $gen->scanDirectory($srcDir);
        $output->writeln(' → Gefundene Dateien: ' . count($items));
        $output->writeln('✨ Erzeuge Rückblicke...');

        $memories = $gen->buildMemories($items, 18);

        if ($jsonOutput) {
            $out = [];
            foreach ($memories as $m) {
                $arr = $m->toArray();
                if ($topN !== null) {
                    $arr['items'] = array_slice($arr['items'], 0, $topN);
                }

                $out[] = $arr;
            }

            $output->writeln(
                json_encode(
                    $out,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                )
            );

            return Command::SUCCESS;
        }

        if ($memories === []) {
            $output->writeln('<comment>⚠️ Keine Rückblicke gefunden.</comment>');

            return Command::SUCCESS;
        }

        foreach ($memories as $i => $m) {
            $output->writeln('');
            $output->writeln(str_repeat('=', 60));
            $output->writeln('Rückblick #' . ($i + 1));
            $output->writeln(str_repeat('=', 60));
            $output->writeln('Titel     : ' . $m->title);
            $output->writeln('Zeitraum  : ' . $m->start->format('d.m.Y') . ' – ' . $m->end->format('d.m.Y'));
            $output->writeln('Cover     : ' . ($m->cover->path ?? '---'));
            $output->writeln('Medien    : ' . count($m->items));

            $items = $m->items;
            usort($items, static fn ($a, $b): int => $b->score <=> $a->score);
            if ($topN !== null) {
                $items = array_slice($items, 0, $topN);
            }

            $sumScore   = array_sum(array_map(static fn (MediaItem $it): float => $it->score, $items));
            $avgScore   = count($items) > 0 ? $sumScore / count($items) : 0.0;
            $maxScore   = count($items) > 0 ? max(array_map(static fn ($it): float => $it->score, $items)) : 0.0;
            $facesTotal = array_sum(array_map(static fn (MediaItem $it): int => count($it->faces), $items));

            if ($summaryOnly) {
                $output->writeln('📊 Zusammenfassung:');
                $output->writeln('   • Durchschnittlicher Score : ' . number_format($avgScore, 2));
                $output->writeln('   • Höchster Score           : ' . number_format($maxScore, 2));
                $output->writeln('   • Erkannte Gesichter       : ' . $facesTotal);
                continue;
            }

            $table = new Table($output);
            $table->setHeaders(['Typ', 'Datei', 'Score']);
            foreach ($items as $it) {
                $table->addRow([
                    strtoupper($it->type),
                    basename($it->path),
                    number_format($it->score, 2),
                ]);
            }

            $table->render();

            $output->writeln('📊 Zusammenfassung:');
            $output->writeln('   • Durchschnittlicher Score : ' . number_format($avgScore, 2));
            $output->writeln('   • Höchster Score           : ' . number_format($maxScore, 2));
            $output->writeln('   • Erkannte Gesichter       : ' . $facesTotal);
        }

        return Command::SUCCESS;
    }
}
