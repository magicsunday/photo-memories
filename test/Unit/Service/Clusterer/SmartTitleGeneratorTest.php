<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\SmartTitleGenerator;
use MagicSunday\Memories\Service\Clusterer\Title\TitleTemplateProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmartTitleGenerator::class)]
#[CoversClass(TitleTemplateProvider::class)]
#[CoversClass(ClusterDraft::class)]
final class SmartTitleGeneratorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->tempFiles as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    #[Test]
    public function rendersTitleAndSubtitleUsingTemplates(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  time_similarity:
    title: "Trip nach {{ place }}"
    subtitle: "{{ date_range }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'time_similarity',
            params: [
                'place' => 'Berlin',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-06-03 20:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Trip nach Berlin', $generator->makeTitle($cluster));
        self::assertSame('01.06. – 03.06.2024', $generator->makeSubtitle($cluster));
    }

    #[Test]
    public function fallsBackToDefaultLocaleWhenRequestedLocaleMissing(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  weekend_trip:
    title: "Wochenendtrip nach {{ place }}"
    subtitle: "{{ start_date }} – {{ end_date }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'weekend_trip',
            params: [
                'place' => 'Hamburg',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-07-05 00:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-07-07 21:59:59', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Wochenendtrip nach Hamburg', $generator->makeTitle($cluster, 'en'));
        self::assertSame('05.07.2024 – 07.07.2024', $generator->makeSubtitle($cluster, 'en'));
    }

    #[Test]
    public function usesFallbacksWhenTemplateIsMissing(): void
    {
        $generator = $this->createGenerator("de: {}\n");

        $cluster = $this->createCluster(
            algorithm: 'unknown',
            params: [
                'label' => 'Sommer 2024',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-08-12 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-08-12 21:59:59', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Sommer 2024', $generator->makeTitle($cluster));
        self::assertSame('12.08.2024', $generator->makeSubtitle($cluster));
    }

    private function createGenerator(string $yaml, string $defaultLocale = 'de'): SmartTitleGenerator
    {
        $path = \tempnam(\sys_get_temp_dir(), 'titles_');
        if ($path === false) {
            self::fail('Failed to create temporary template file.');
        }

        $result = \file_put_contents($path, $yaml);
        if ($result === false) {
            self::fail('Failed to write template configuration.');
        }

        $this->tempFiles[] = $path;

        $provider = new TitleTemplateProvider($path, $defaultLocale);

        return new SmartTitleGenerator($provider);
    }

    /**
     * @param array<string, scalar|array|null> $params
     */
    private function createCluster(string $algorithm, array $params): ClusterDraft
    {
        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );
    }
}
