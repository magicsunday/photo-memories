<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Command;

use MagicSunday\Memories\Command\MemoriesCurateCommand;
use MagicSunday\Memories\Command\MemoriesCurateVacationCommand;
use MagicSunday\Memories\Service\Clusterer\ClusterJobResult;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Service\Explainability\ClusterModelCardWriter;
use MagicSunday\Memories\Service\Feed\Contract\FeedExportServiceInterface;
use MagicSunday\Memories\Service\Feed\FeedExportResult;
use MagicSunday\Memories\Service\Feed\FeedExportStage;
use MagicSunday\Memories\Service\Indexing\MediaFileLocatorInterface;
use MagicSunday\Memories\Service\Indexing\MediaIngestionPipelineInterface;
use MagicSunday\Memories\Service\Metadata\MetadataQaReportCollector;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function sys_get_temp_dir;
use function uniqid;

final class MemoriesCurateVacationCommandTest extends TestCase
{
    private const CLUSTER_GROUP_MAP = [
        'vacation' => 'travel_and_places',
        'year_in_review' => 'time_and_basics',
        'person_cohort' => 'people_and_moments',
        'holiday_event' => 'city_and_events',
    ];

    private const CLUSTER_GROUP_ALIAS_MAP = [
        'vacation' => 'travel_and_places',
        'people' => 'people_and_moments',
        'events' => 'city_and_events',
    ];

    #[Test]
    public function itWarnsAndDelegatesToTheCurateCommand(): void
    {
        $curateCommand = $this->createCurateCommand();

        $captured = [];
        $curateCommand->setCode(static function (InputInterface $input, OutputInterface $output) use (&$captured): int {
            $captured = [
                'types'   => $input->getOption('types'),
                'path'    => $input->getArgument('path'),
                'dry-run' => $input->getOption('dry-run'),
            ];

            return Command::SUCCESS;
        });

        $aliasCommand = new MemoriesCurateVacationCommand($curateCommand);
        $tester       = new CommandTester($aliasCommand);

        $status = $tester->execute([
            'path'      => '/data/media/custom',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['vacation'], $captured['types']);
        self::assertTrue($captured['dry-run']);
        self::assertSame('/data/media/custom', $captured['path']);
        self::assertStringContainsString('ist veraltet', $tester->getDisplay());
    }

    #[Test]
    public function itOverridesProvidedTypesWithVacation(): void
    {
        $curateCommand = $this->createCurateCommand();

        $capturedTypes = null;
        $curateCommand->setCode(static function (InputInterface $input, OutputInterface $output) use (&$capturedTypes): int {
            $capturedTypes = $input->getOption('types');

            return Command::SUCCESS;
        });

        $aliasCommand = new MemoriesCurateVacationCommand($curateCommand);
        $tester       = new CommandTester($aliasCommand);

        $tester->execute([
            '--types' => ['people'],
        ]);

        self::assertSame(['vacation'], $capturedTypes);
    }

    private function createCurateCommand(): MemoriesCurateCommand
    {
        $locator = $this->createMock(MediaFileLocatorInterface::class);
        $pipeline = $this->createMock(MediaIngestionPipelineInterface::class);
        $qaCollector = new MetadataQaReportCollector();
        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->method('run')->willReturn(new ClusterJobResult(0, 0, 0, 0, 0, 0, false, null, []));
        $export = $this->createMock(FeedExportServiceInterface::class);
        $export->method('export')->willReturn(new FeedExportResult(
            'out',
            'images',
            null,
            0,
            0,
            0,
            FeedExportStage::Curated,
            [
                FeedExportStage::Raw->value     => 0,
                FeedExportStage::Merged->value  => 0,
                FeedExportStage::Curated->value => 0,
            ],
        ));

        return new MemoriesCurateCommand(
            $locator,
            $pipeline,
            $qaCollector,
            $runner,
            $export,
            $this->createModelCardWriter(),
            self::CLUSTER_GROUP_MAP,
            self::CLUSTER_GROUP_ALIAS_MAP,
            $this->getExistingMediaPath(),
        );
    }

    private function createModelCardWriter(): ClusterModelCardWriter
    {
        $directory = sys_get_temp_dir() . '/memories-modelcards-' . uniqid('', true);

        return new ClusterModelCardWriter($directory);
    }

    private function getExistingMediaPath(): string
    {
        return __DIR__;
    }
}
