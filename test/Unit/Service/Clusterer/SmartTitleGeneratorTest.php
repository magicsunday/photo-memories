<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\SmartTitleGenerator;
use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\TitleTemplateProvider;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

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
            if (is_file($path)) {
                @unlink($path);
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
    title: "Trip nach {{ place_city|place }}"
    subtitle: "{{ date_range }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'time_similarity',
            params: [
                'place'      => 'Berlin',
                'place_city' => 'Berlin',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-06-03 20:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Trip nach Berlin', $generator->makeTitle($cluster));
        self::assertSame('1.–3. Jun. 2024', $generator->makeSubtitle($cluster));
    }

    #[Test]
    public function fallsBackToDefaultLocaleWhenRequestedLocaleMissing(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  vacation:
    title: "Reise nach {{ place_city|place_region|place_country|place }}"
    subtitle: "{{ start_date }} – {{ end_date }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'vacation',
            params: [
                'place'         => 'Hamburg',
                'place_city'    => 'Hamburg',
                'place_country' => 'Germany',
                'time_range'    => [
                    'from' => (new DateTimeImmutable('2024-07-05 00:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-07-07 21:59:59', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Reise nach Hamburg', $generator->makeTitle($cluster, 'en'));
        self::assertSame('5.–7. Jul. 2024', $generator->makeSubtitle($cluster, 'en'));
    }

    #[Test]
    public function usesFallbacksWhenTemplateIsMissing(): void
    {
        $generator = $this->createGenerator("de: {}\n");

        $cluster = $this->createCluster(
            algorithm: 'unknown',
            params: [
                'label'      => 'Sommer 2024',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-08-12 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-08-12 21:59:59', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Sommer 2024', $generator->makeTitle($cluster));
        self::assertSame('12. Aug. 2024', $generator->makeSubtitle($cluster));
    }

    #[Test]
    public function fallsBackToPlaceWhenComponentsMissing(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  vacation:
    title: "Reise nach {{ place_city|place_region|place_country|place }}"
    subtitle: "{{ start_date }} – {{ end_date }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'vacation',
            params: [
                'place'      => 'Nordsee',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-09-14 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-09-16 21:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Reise nach Nordsee', $generator->makeTitle($cluster));
    }

    #[Test]
    public function prefersRegionWhenCityIsMissing(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  vacation:
    title: "Reise nach {{ place_city|place_region|place_country|place }}"
    subtitle: "{{ start_date }} – {{ end_date }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'vacation',
            params: [
                'place_region' => 'Lombardei',
                'place_country' => 'Italien',
                'time_range'    => [
                    'from' => (new DateTimeImmutable('2024-10-10 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-10-15 22:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Reise nach Lombardei', $generator->makeTitle($cluster));
    }

    #[Test]
    public function normalizesLowercasePlaceValues(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  location_similarity:
    title: "Unterwegs in {{ place }}"
    subtitle: "{{ place_location }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'location_similarity',
            params: [
                'place'           => 'monterosso al mare',
                'place_city'      => 'monterosso al mare',
                'place_region'    => 'ligurien',
                'place_country'   => 'italien',
                'place_location'  => 'monterosso al mare, ligurien, italien',
                'time_range'      => [
                    'from' => (new DateTimeImmutable('2024-07-06 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-07-06 18:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Unterwegs in Monterosso Al Mare', $generator->makeTitle($cluster));
        self::assertSame('Monterosso Al Mare, Ligurien, Italien', $generator->makeSubtitle($cluster));
    }

    #[Test]
    public function rendersVacationRouteWithMetrics(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  vacation:
    title: "{{ vacation_title }}"
    subtitle: "{{ vacation_subtitle }}"
YAML
        );

        $cluster = $this->createCluster(
            algorithm: 'vacation',
            params: [
                'classification_label' => 'Urlaub',
                'place_country'       => 'Deutschland',
                'time_range'          => [
                    'from' => (new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-06-03 20:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
                'travel_waypoints'    => [
                    [
                        'label'         => 'Alpha',
                        'city'          => 'Alpha',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 5,
                        'first_seen_at' => 100,
                        'lat'           => 0.0,
                        'lon'           => 0.0,
                    ],
                    [
                        'label'         => 'Beta',
                        'city'          => 'Beta',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 4,
                        'first_seen_at' => 200,
                        'lat'           => 0.0,
                        'lon'           => 1.0,
                    ],
                    [
                        'label'         => 'Gamma',
                        'city'          => 'Gamma',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 3,
                        'first_seen_at' => 300,
                        'lat'           => 1.0,
                        'lon'           => 1.0,
                    ],
                ],
            ],
        );

        self::assertSame('Alpha → Beta → Gamma', $generator->makeTitle($cluster));
        self::assertSame('ca. 220 km • 3 Stopps • 1.–3. Jun. 2024', $generator->makeSubtitle($cluster));
    }

    #[Test]
    public function rendersSpecialSubtitlesForHighlightedPlaces(): void
    {
        $generator = $this->createGenerator(<<<'YAML'
de:
  significant_place:
    title: "Besonderer Ort: {{ place }}"
    subtitle: "{{ subtitle_special|date_range }}"
  nightlife_event:
    title: "Abend in der Stadt"
    subtitle: "{{ subtitle_special|date_range }}"
YAML
        );

        $significant = $this->createCluster(
            algorithm: 'significant_place',
            params: [
                'place'      => 'Berlin',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-03-15 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-03-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Lieblingsort • Berlin • 15. Mär. 2024', $generator->makeSubtitle($significant));

        $nightlife = $this->createCluster(
            algorithm: 'nightlife_event',
            params: [
                'place_city' => 'Hamburg',
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-04-01 18:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-04-02 02:00:00', new DateTimeZone('UTC')))->getTimestamp(),
                ],
            ],
        );

        self::assertSame('Nachtleben • Hamburg • 1.–2. Apr. 2024', $generator->makeSubtitle($nightlife));
    }

    private function createGenerator(string $yaml, string $defaultLocale = 'de'): SmartTitleGenerator
    {
        $path = tempnam(sys_get_temp_dir(), 'titles_');
        if ($path === false) {
            self::fail('Failed to create temporary template file.');
        }

        $result = file_put_contents($path, $yaml);
        if ($result === false) {
            self::fail('Failed to write template configuration.');
        }

        $this->tempFiles[] = $path;

        $provider       = new TitleTemplateProvider($path, $defaultLocale);
        $routeSummarizer = new RouteSummarizer();
        $dateFormatter   = new LocalizedDateFormatter();

        return new SmartTitleGenerator($provider, $routeSummarizer, $dateFormatter);
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
