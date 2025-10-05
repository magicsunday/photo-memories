<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\CohortPresenceStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\DefaultDaySummaryBuilder;
use MagicSunday\Memories\Clusterer\DefaultHomeLocator;
use MagicSunday\Memories\Clusterer\DefaultVacationSegmentAssembler;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\RunDetector;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Service\TransportDayExtender;
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class DefaultVacationSegmentAssemblerTest extends TestCase
{
    #[Test]
    public function detectSegmentsMergesDstTransitionDays(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $homeLocator    = new DefaultHomeLocator(
            timezone: 'Europe/Berlin',
            defaultHomeRadiusKm: 12.0,
            homeLat: 52.5200,
            homeLon: 13.4050,
            homeRadiusKm: 12.0,
        );

        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $dayBuilder       = new DefaultDaySummaryBuilder([
            new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin'),
            new CohortPresenceStage(),
            new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), 1.0, 3, 2),
            new DensityStage(),
            new AwayFlagStage($timezoneResolver, new BaseLocationResolver()),
        ]);

        $transportExtender = new TransportDayExtender();
        $runDetector       = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 80.0,
            minItemsPerDay: 2,
        );
        $scoreCalculator = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 25.0,
        );

        $assembler = new DefaultVacationSegmentAssembler($runDetector, $scoreCalculator);

        $tripLocation = $this->makeLocation('trip-lisbon', 'Lisboa, Portugal', 38.7223, -9.1393, country: 'Portugal', configure: static function (Location $loc): void {
            $loc->setCategory('tourism');
            $loc->setType('attraction');
            $loc->setCountryCode('PT');
        });

        $items = [];
        $id    = 1000;

        $tripStart = new DateTimeImmutable('2024-03-29 09:00:00', new DateTimeZone('Europe/Berlin'));
        $offsets   = [0, 0, 60, 60, 60];
        foreach ($offsets as $dayIndex => $offset) {
            $dayStart = $tripStart->add(new DateInterval('P' . $dayIndex . 'D'));
            for ($photo = 0; $photo < 4; ++$photo) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($photo * 6) . 'H'));
                $items[]   = $this->makeMediaFixture(
                    ++$id,
                    sprintf('trip-day-%d-%d.jpg', $dayIndex, $photo),
                    $timestamp,
                    $tripLocation->getLat() + ($photo * 0.005),
                    $tripLocation->getLon() + ($photo * 0.005),
                    $tripLocation,
                    static function (Media $media) use ($offset): void {
                        $media->setTimezoneOffsetMin($offset);
                    }
                );
            }
        }

        $home = $homeLocator->determineHome($items);
        self::assertNotNull($home);

        $days     = $dayBuilder->buildDaySummaries($items, $home);
        $clusters = $assembler->detectSegments($days, $home);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('vacation', $cluster->getAlgorithm());
        $params = $cluster->getParams();
        self::assertSame('vacation', $params['classification']);
        self::assertSame(6, $params['away_days']);
        self::assertEqualsCanonicalizing([0, 60], $params['timezones']);
        self::assertSame(['pt'], $params['countries']);
    }
}
