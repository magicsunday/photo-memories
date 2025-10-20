<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Command;

use DateTimeImmutable;
use MagicSunday\Memories\Command\ClusterCommand;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterJobResult;
use MagicSunday\Memories\Service\Clusterer\ClusterJobTelemetry;
use MagicSunday\Memories\Service\Clusterer\ClusterSummary;
use MagicSunday\Memories\Service\Clusterer\ClusterSummaryTimeRange;
use MagicSunday\Memories\Service\Clusterer\ConsoleProgressReporter;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Service\Clusterer\Debug\VacationDebugContext;
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

        $command = new ClusterCommand($runner, $this->createSelectionProfileProvider());
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
                    self::assertFalse($options->isVacationDebugEnabled());
                    self::assertNull($options->getUntil());
                    self::assertNull($options->getAllowedGroups());

                    return true;
                }),
                self::callback(function ($reporter): bool {
                    self::assertInstanceOf(ConsoleProgressReporter::class, $reporter);

                    return true;
                }),
            )
            ->willReturn(
                new ClusterJobResult(
                    5,
                    4,
                    3,
                    2,
                    2,
                    0,
                    true,
                    ClusterJobTelemetry::fromStageStats(
                        [
                            ClusterJobTelemetry::STAGE_DRAFTS => [
                                'clusters'     => 3,
                                'members_pre'  => 90,
                                'members_post' => 90,
                            ],
                            ClusterJobTelemetry::STAGE_CONSOLIDATED => [
                                'clusters'     => 2,
                                'members_pre'  => 90,
                                'members_post' => 60,
                            ],
                        ],
                        [
                            new ClusterSummary(
                                'algo-top',
                                'storyline-top',
                                5,
                                4,
                                'default',
                                1.23,
                                new ClusterSummaryTimeRange(
                                    new DateTimeImmutable('2024-05-17T00:00:00+00:00'),
                                    new DateTimeImmutable('2024-05-18T00:00:00+00:00'),
                                ),
                            ),
                        ],
                    ),
                    [],
                ),
            );

        $command = new ClusterCommand($runner, $this->createSelectionProfileProvider());
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
        self::assertStringContainsString('📊 Telemetrie', $display);
        self::assertStringContainsString('Entwürfe', $display);
        self::assertStringContainsString('Konsolidiert', $display);
        self::assertStringContainsString('Mitglieder vor (roh)', $display);
        self::assertStringContainsString('Mitglieder nach (roh)', $display);
        self::assertStringContainsString('algo-top', $display);
        self::assertStringContainsString('storyline-top', $display);
        self::assertStringContainsString('default', $display);
        self::assertStringContainsString('Mitglieder (roh)', $display);
        self::assertStringContainsString('Mitglieder (kuratiert)', $display);
        self::assertStringContainsString('1.23', $display);
        self::assertStringContainsString('2024-05-17 → 2024-05-18', $display);
    }

    #[Test]
    public function executeEnablesVacationDebugContext(): void
    {
        $context = new VacationDebugContext();

        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(
                self::callback(function (ClusterJobOptions $options) use ($context): bool {
                    self::assertTrue($options->isVacationDebugEnabled());
                    self::assertTrue($context->isEnabled());

                    return true;
                }),
                self::isInstanceOf(ConsoleProgressReporter::class),
            )
            ->willReturn(
                new ClusterJobResult(
                    1,
                    1,
                    1,
                    1,
                    1,
                    0,
                    false,
                    ClusterJobTelemetry::fromStageStats([
                        ClusterJobTelemetry::STAGE_DRAFTS => [
                            'clusters'     => 1,
                            'members_pre'  => 12,
                            'members_post' => 12,
                        ],
                        ClusterJobTelemetry::STAGE_CONSOLIDATED => [
                            'clusters'     => 1,
                            'members_pre'  => 12,
                            'members_post' => 10,
                        ],
                    ]),
                    [],
                ),
            );

        $command = new ClusterCommand($runner, $this->createSelectionProfileProvider(), $context);
        $tester  = new CommandTester($command);

        $status = $tester->execute([
            '--debug-vacation' => true,
        ], [
            'decorated' => false,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFalse($context->isEnabled());
    }

    #[Test]
    public function renderTelemetryDisplaysWarnings(): void
    {
        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->willReturn(
                new ClusterJobResult(
                    1,
                    1,
                    1,
                    1,
                    1,
                    0,
                    false,
                    ClusterJobTelemetry::fromStageStats(
                        [
                            ClusterJobTelemetry::STAGE_DRAFTS => [
                                'clusters'     => 1,
                                'members_pre'  => 8,
                                'members_post' => 8,
                            ],
                            ClusterJobTelemetry::STAGE_CONSOLIDATED => [
                                'clusters'     => 1,
                                'members_pre'  => 8,
                                'members_post' => 6,
                            ],
                        ],
                        warnings: [
                            'Konfiguration unvollständig: MEMORIES_HOME_LAT/MEMORIES_HOME_LON stehen auf 0/0. Bitte gültige Koordinaten und MEMORIES_HOME_RADIUS_KM setzen.',
                        ],
                    ),
                    [],
                ),
            );

        $command = new ClusterCommand($runner, $this->createSelectionProfileProvider());
        $tester  = new CommandTester($command);

        $status = $tester->execute([], ['decorated' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Konfiguration unvollständig', $tester->getDisplay());
    }

    private function createSelectionProfileProvider(): SelectionProfileProvider
    {
        return new SelectionProfileProvider(new VacationSelectionOptions());
    }
}
