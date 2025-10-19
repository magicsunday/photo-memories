<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\AnniversaryClusterStrategy;
use MagicSunday\Memories\Clusterer\AtHomeWeekdayClusterStrategy;
use MagicSunday\Memories\Clusterer\AtHomeWeekendClusterStrategy;
use MagicSunday\Memories\Clusterer\BurstClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Clusterer\CrossDimensionClusterStrategy;
use MagicSunday\Memories\Clusterer\DayAlbumClusterStrategy;
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\DefaultDaySummaryBuilder;
use MagicSunday\Memories\Clusterer\DefaultHomeLocator;
use MagicSunday\Memories\Clusterer\DefaultVacationSegmentAssembler;
use MagicSunday\Memories\Clusterer\DeviceSimilarityStrategy;
use MagicSunday\Memories\Clusterer\FirstVisitPlaceClusterStrategy;
use MagicSunday\Memories\Clusterer\GoldenHourClusterStrategy;
use MagicSunday\Memories\Clusterer\HolidayEventClusterStrategy;
use MagicSunday\Memories\Clusterer\LocationSimilarityStrategy;
use MagicSunday\Memories\Clusterer\MonthlyHighlightsClusterStrategy;
use MagicSunday\Memories\Clusterer\NewYearEveClusterStrategy;
use MagicSunday\Memories\Clusterer\NightlifeEventClusterStrategy;
use MagicSunday\Memories\Clusterer\OneYearAgoClusterStrategy;
use MagicSunday\Memories\Clusterer\OnThisDayOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\PanoramaClusterStrategy;
use MagicSunday\Memories\Clusterer\PanoramaOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\PersonCohortClusterStrategy;
use MagicSunday\Memories\Clusterer\PhashSimilarityStrategy;
use MagicSunday\Memories\Clusterer\PortraitOrientationClusterStrategy;
use MagicSunday\Memories\Clusterer\SeasonClusterStrategy;
use MagicSunday\Memories\Clusterer\SeasonOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\RunDetector;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Service\TransportDayExtender;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Clusterer\SignificantPlaceClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\ThisMonthOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\TimeSimilarityStrategy;
use MagicSunday\Memories\Clusterer\TransitTravelDayClusterStrategy;
use MagicSunday\Memories\Clusterer\VacationClusterStrategy;
use MagicSunday\Memories\Clusterer\VideoStoriesClusterStrategy;
use MagicSunday\Memories\Clusterer\WeekendGetawaysOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\YearInReviewClusterStrategy;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Service\Clusterer\Title\StoryTitleBuilder;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\VacationTestMemberSelector;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClusterStrategySmokeTest extends TestCase
{
    /**
     * Ensures every cluster strategy exposes its declared name and gracefully handles empty input.
     *
     * @param class-string<ClusterStrategyInterface>   $class
     * @param callable():ClusterStrategyInterface|null $factory
     */
    #[DataProvider('strategyProvider')]
    public function testStrategyReportsNameAndHandlesEmptyInput(
        string $class,
        string $expectedName,
        ?callable $factory = null,
    ): void {
        $strategy = $factory !== null
            ? $factory()
            : $this->instantiateWithoutDependencies($class);

        self::assertInstanceOf(ClusterStrategyInterface::class, $strategy);
        self::assertSame($expectedName, $strategy->name());
        self::assertSame([], $strategy->draft([], Context::fromScope([])));
    }

    /**
     * @return iterable<string, array{class-string<ClusterStrategyInterface>, string, callable():ClusterStrategyInterface|null}>
     */
    public static function strategyProvider(): iterable
    {
        yield 'AnniversaryClusterStrategy' => [
            AnniversaryClusterStrategy::class,
            'anniversary',
            static fn (): ClusterStrategyInterface => new AnniversaryClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'AtHomeWeekdayClusterStrategy' => [
            AtHomeWeekdayClusterStrategy::class,
            'at_home_weekday',
            static fn (): ClusterStrategyInterface => new AtHomeWeekdayClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'AtHomeWeekendClusterStrategy' => [
            AtHomeWeekendClusterStrategy::class,
            'at_home_weekend',
            static fn (): ClusterStrategyInterface => new AtHomeWeekendClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'BurstClusterStrategy' => [
            BurstClusterStrategy::class,
            'burst',
            static fn (): ClusterStrategyInterface => new BurstClusterStrategy(
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'CrossDimensionClusterStrategy' => [
            CrossDimensionClusterStrategy::class,
            'cross_dimension',
            null,
        ];
        yield 'DayAlbumClusterStrategy' => [
            DayAlbumClusterStrategy::class,
            'day_album',
            static fn (): ClusterStrategyInterface => new DayAlbumClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'DeviceSimilarityStrategy' => [
            DeviceSimilarityStrategy::class,
            'device_similarity',
            static fn (): ClusterStrategyInterface => new DeviceSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'FirstVisitPlaceClusterStrategy' => [
            FirstVisitPlaceClusterStrategy::class,
            'first_visit_place',
            static fn (): ClusterStrategyInterface => new FirstVisitPlaceClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'GoldenHourClusterStrategy' => [
            GoldenHourClusterStrategy::class,
            'golden_hour',
            static fn (): ClusterStrategyInterface => new GoldenHourClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'HolidayEventClusterStrategy' => [
            HolidayEventClusterStrategy::class,
            'holiday_event',
            static fn (): ClusterStrategyInterface => new HolidayEventClusterStrategy(
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'LocationSimilarityStrategy' => [
            LocationSimilarityStrategy::class,
            'location_similarity',
            static fn (): ClusterStrategyInterface => new LocationSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'VacationClusterStrategy' => [
            VacationClusterStrategy::class,
            'vacation',
            static fn (): ClusterStrategyInterface => (function (): ClusterStrategyInterface {
                $storyTitleBuilder = new StoryTitleBuilder(
                    new RouteSummarizer(),
                    new LocalizedDateFormatter(),
                );

                return new VacationClusterStrategy(
                    new DefaultHomeLocator(),
                    new DefaultDaySummaryBuilder([
                        new InitializationStage(new TimezoneResolver(), new PoiClassifier()),
                        new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector()),
                        new DensityStage(),
                        new AwayFlagStage(new TimezoneResolver(), new BaseLocationResolver()),
                    ]),
                    new DefaultVacationSegmentAssembler(
                        new RunDetector(new TransportDayExtender()),
                        new VacationScoreCalculator(
                            locationHelper: self::locationHelper(),
                            memberSelector: new VacationTestMemberSelector(),
                            selectionProfiles: new SelectionProfileProvider(new VacationSelectionOptions(), 'vacation'),
                            storyTitleBuilder: $storyTitleBuilder,
                            holidayResolver: new NullHolidayResolver(),
                            minItemsPerDay: 4,
                            minimumMemberFloor: 0,
                        ),
                        $storyTitleBuilder,
                    ),
                );
            })(),
        ];
        yield 'MonthlyHighlightsClusterStrategy' => [
            MonthlyHighlightsClusterStrategy::class,
            'monthly_highlights',
            null,
        ];
        yield 'NewYearEveClusterStrategy' => [
            NewYearEveClusterStrategy::class,
            'new_year_eve',
            static fn (): ClusterStrategyInterface => new NewYearEveClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'NightlifeEventClusterStrategy' => [
            NightlifeEventClusterStrategy::class,
            'nightlife_event',
            static fn (): ClusterStrategyInterface => new NightlifeEventClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'OnThisDayOverYearsClusterStrategy' => [
            OnThisDayOverYearsClusterStrategy::class,
            'on_this_day_over_years',
            static fn (): ClusterStrategyInterface => new OnThisDayOverYearsClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'OneYearAgoClusterStrategy' => [
            OneYearAgoClusterStrategy::class,
            'one_year_ago',
            static fn (): ClusterStrategyInterface => new OneYearAgoClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'PanoramaClusterStrategy' => [
            PanoramaClusterStrategy::class,
            'panorama',
            null,
        ];
        yield 'PanoramaOverYearsClusterStrategy' => [
            PanoramaOverYearsClusterStrategy::class,
            'panorama_over_years',
            null,
        ];
        yield 'PersonCohortClusterStrategy' => [
            PersonCohortClusterStrategy::class,
            'people_cohort',
            static fn (): ClusterStrategyInterface => new PersonCohortClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'PhashSimilarityStrategy' => [
            PhashSimilarityStrategy::class,
            'phash_similarity',
            static fn (): ClusterStrategyInterface => new PhashSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'PortraitOrientationClusterStrategy' => [
            PortraitOrientationClusterStrategy::class,
            'portrait_orientation',
            static fn (): ClusterStrategyInterface => new PortraitOrientationClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'SeasonClusterStrategy' => [
            SeasonClusterStrategy::class,
            'season',
            static fn (): ClusterStrategyInterface => new SeasonClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'SeasonOverYearsClusterStrategy' => [
            SeasonOverYearsClusterStrategy::class,
            'season_over_years',
            static fn (): ClusterStrategyInterface => new SeasonOverYearsClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'SignificantPlaceClusterStrategy' => [
            SignificantPlaceClusterStrategy::class,
            'significant_place',
            static fn (): ClusterStrategyInterface => new SignificantPlaceClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'ThisMonthOverYearsClusterStrategy' => [
            ThisMonthOverYearsClusterStrategy::class,
            'this_month_over_years',
            static fn (): ClusterStrategyInterface => new ThisMonthOverYearsClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'TimeSimilarityStrategy' => [
            TimeSimilarityStrategy::class,
            'time_similarity',
            static fn (): ClusterStrategyInterface => new TimeSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'TransitTravelDayClusterStrategy' => [
            TransitTravelDayClusterStrategy::class,
            'transit_travel_day',
            static fn (): ClusterStrategyInterface => new TransitTravelDayClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'VideoStoriesClusterStrategy' => [
            VideoStoriesClusterStrategy::class,
            'video_stories',
            static fn (): ClusterStrategyInterface => new VideoStoriesClusterStrategy(
                localTimeHelper: self::localTimeHelper(),
                locationHelper: self::locationHelper()
            ),
        ];
        yield 'WeekendGetawaysOverYearsClusterStrategy' => [
            WeekendGetawaysOverYearsClusterStrategy::class,
            'weekend_getaways_over_years',
            static fn (): ClusterStrategyInterface => new WeekendGetawaysOverYearsClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'YearInReviewClusterStrategy' => [
            YearInReviewClusterStrategy::class,
            'year_in_review',
            static fn (): ClusterStrategyInterface => new YearInReviewClusterStrategy(
                self::locationHelper()
            ),
        ];
    }

    private static function locationHelper(): LocationHelper
    {
        return LocationHelper::createDefault();
    }

    private static function localTimeHelper(): LocalTimeHelper
    {
        return new LocalTimeHelper('Europe/Berlin');
    }

    /**
     * @param class-string<ClusterStrategyInterface> $class
     */
    private function instantiateWithoutDependencies(string $class): ClusterStrategyInterface
    {
        /** @var ClusterStrategyInterface $instance */
        $instance = new $class();

        return $instance;
    }
}
