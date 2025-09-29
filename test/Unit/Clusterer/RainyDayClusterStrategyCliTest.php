<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\RainyDayClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class RainyDayClusterStrategyCliTest extends TestCase
{
    #[Test]
    public function cliEmitsRainyDayCluster(): void
    {
        $provider = new CliRainProvider([
            7000 => ['rain_prob' => 0.9, 'precip_mm' => 5.1],
            7001 => ['rain_prob' => 0.8, 'precip_mm' => 4.0],
            7002 => ['rain_prob' => 0.7, 'precip_mm' => 3.5],
        ]);

        $strategy = new RainyDayClusterStrategy(
            weather: $provider,
            timezone: 'UTC',
            minAvgRainProb: 0.6,
            minItemsPerDay: 3,
        );

        $base  = new DateTimeImmutable('2024-07-10 14:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->makeMediaFixture(
                id: 7000 + $i,
                filename: \sprintf('rain-%d.jpg', $i),
                takenAt: $base->add(new DateInterval('PT' . ($i * 900) . 'S')),
                lat: 53.0,
                lon: 8.0,
            );
        }

        $command = new class($strategy, $items) extends Command {
            /** @var list<Media> */
            private readonly array $items;

            /**
             * @param list<Media> $items
             */
            public function __construct(
                private readonly RainyDayClusterStrategy $strategy,
                array $items
            ) {
                parent::__construct('test:rainy-day');
                $this->items = $items;
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $clusters = $this->strategy->cluster($this->items);
                foreach ($clusters as $cluster) {
                    $output->writeln(\sprintf(
                        '%s | members: %s | rain: %.2f',
                        $cluster->getAlgorithm(),
                        \implode(',', $cluster->getMembers()),
                        $cluster->getParams()['rain_prob'] ?? 0.0
                    ));
                }

                return Command::SUCCESS;
            }
        };

        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($application->find('test:rainy-day'));
        $tester->execute([]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('rainy_day', $output);
        self::assertStringContainsString('7000,7001,7002', $output);
    }
}

/**
 * @internal test helper
 */
final readonly class CliRainProvider implements WeatherHintProviderInterface
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

