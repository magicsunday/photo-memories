<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\SunnyDayClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class SunnyDayClusterStrategyCliTest extends TestCase
{
    #[Test]
    public function cliEmitsSunnyDayCluster(): void
    {
        $provider = new CliWeatherProvider([
            6000 => ['sun_prob' => 0.9],
            6001 => ['sun_prob' => 0.85],
            6002 => ['sun_prob' => 0.8],
        ]);

        $strategy = new SunnyDayClusterStrategy(
            weather: $provider,
            timezone: 'UTC',
            minAvgSunScore: 0.7,
            minItemsPerDay: 3,
            minHintsPerDay: 2,
        );

        $base  = new DateTimeImmutable('2024-07-01 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->makeMediaFixture(
                id: 6000 + $i,
                filename: \sprintf('sunny-%d.jpg', $i),
                takenAt: $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
                lat: 48.0,
                lon: 11.0,
            );
        }

        $command = new class($strategy, $items) extends Command {
            /** @var list<Media> */
            private readonly array $items;

            /**
             * @param list<Media> $items
             */
            public function __construct(
                private readonly SunnyDayClusterStrategy $strategy,
                array $items
            ) {
                parent::__construct('test:sunny-day');
                $this->items = $items;
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $clusters = $this->strategy->cluster($this->items);
                foreach ($clusters as $cluster) {
                    $output->writeln(\sprintf(
                        '%s | members: %s',
                        $cluster->getAlgorithm(),
                        \implode(',', $cluster->getMembers())
                    ));
                }

                return Command::SUCCESS;
            }
        };

        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($application->find('test:sunny-day'));
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('sunny_day', $output);
        self::assertStringContainsString('6000,6001,6002', $output);
    }
}

/**
 * @internal test helper
 */
final readonly class CliWeatherProvider implements WeatherHintProviderInterface
{
    /** @param array<int, array<string, float>> $hints */
    public function __construct(private array $hints)
    {
    }

    public function getHint(Media $media): ?array
    {
        $id = $media->getId();

        return $this->hints[$id] ?? null;
    }
}

