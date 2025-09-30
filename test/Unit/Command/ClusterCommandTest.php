<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Command;

use MagicSunday\Memories\Command\ClusterCommand;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterJobResult;
use MagicSunday\Memories\Service\Clusterer\ConsoleProgressReporter;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ClusterCommandTest extends TestCase
{
    #[Test]
    public function executeRejectsInvalidSinceDate(): void
    {
        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->expects(self::never())->method('run');

        $command = new ClusterCommand($runner);
        $tester  = new CommandTester($command);

        $status = $tester->execute([
            '--since' => 'not-a-date',
        ], [
            'decorated' => false,
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Invalid "since" date. Use YYYY-MM-DD.', $tester->getDisplay());
    }

    #[Test]
    public function executeDelegatesToRunnerAndPrintsSummary(): void
    {
        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(
                self::callback(function (ClusterJobOptions $options): bool {
                    self::assertTrue($options->isDryRun());
                    self::assertSame(25, $options->getLimit());
                    self::assertNotNull($options->getSince());
                    self::assertTrue($options->shouldReplace());

                    return true;
                }),
                self::callback(function ($reporter): bool {
                    self::assertInstanceOf(ConsoleProgressReporter::class, $reporter);

                    return true;
                }),
            )
            ->willReturn(new ClusterJobResult(5, 4, 3, 2, 2, 0, true));

        $command = new ClusterCommand($runner);
        $tester  = new CommandTester($command);

        $status = $tester->execute([
            '--dry-run' => true,
            '--limit'   => '25',
            '--since'   => '2023-05-17',
            '--replace' => true,
        ], [
            'decorated' => false,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('4 Medien geladen.', $display);
        self::assertStringContainsString('3 Cluster vorgeschlagen.', $display);
        self::assertStringContainsString('3 → 2 Cluster nach Konsolidierung.', $display);
        self::assertStringContainsString('2 Cluster gespeichert.', $display);
    }
}
