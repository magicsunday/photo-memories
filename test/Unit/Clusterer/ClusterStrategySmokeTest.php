<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\AnniversaryClusterStrategy;
use MagicSunday\Memories\Clusterer\AtHomeWeekdayClusterStrategy;
use MagicSunday\Memories\Clusterer\AtHomeWeekendClusterStrategy;
use MagicSunday\Memories\Clusterer\BurstClusterStrategy;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Clusterer\CrossDimensionClusterStrategy;
use MagicSunday\Memories\Clusterer\DayAlbumClusterStrategy;
use MagicSunday\Memories\Clusterer\DeviceSimilarityStrategy;
use MagicSunday\Memories\Clusterer\FirstVisitPlaceClusterStrategy;
use MagicSunday\Memories\Clusterer\GoldenHourClusterStrategy;
use MagicSunday\Memories\Clusterer\HolidayEventClusterStrategy;
use MagicSunday\Memories\Clusterer\LocationSimilarityStrategy;
use MagicSunday\Memories\Clusterer\VacationClusterStrategy;
use MagicSunday\Memories\Clusterer\DefaultDaySummaryBuilder;
use MagicSunday\Memories\Clusterer\DefaultHomeLocator;
use MagicSunday\Memories\Clusterer\DefaultVacationSegmentAssembler;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
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
use MagicSunday\Memories\Clusterer\SignificantPlaceClusterStrategy;
use MagicSunday\Memories\Clusterer\ThisMonthOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\TimeSimilarityStrategy;
use MagicSunday\Memories\Clusterer\TransitTravelDayClusterStrategy;
use MagicSunday\Memories\Clusterer\VideoStoriesClusterStrategy;
use MagicSunday\Memories\Clusterer\WeekendGetawaysOverYearsClusterStrategy;
use MagicSunday\Memories\Clusterer\YearInReviewClusterStrategy;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
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
        self::assertSame([], $strategy->cluster([]));
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
            null,
        ];
        yield 'AtHomeWeekendClusterStrategy' => [
            AtHomeWeekendClusterStrategy::class,
            'at_home_weekend',
            null,
        ];
        yield 'BurstClusterStrategy' => [
            BurstClusterStrategy::class,
            'burst',
            null,
        ];
        yield 'CrossDimensionClusterStrategy' => [
            CrossDimensionClusterStrategy::class,
            'cross_dimension',
            null,
        ];
        yield 'DayAlbumClusterStrategy' => [
            DayAlbumClusterStrategy::class,
            'day_album',
            null,
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
            null,
        ];
        yield 'HolidayEventClusterStrategy' => [
            HolidayEventClusterStrategy::class,
            'holiday_event',
            null,
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
            static fn (): ClusterStrategyInterface => new VacationClusterStrategy(
                new DefaultHomeLocator(),
                new DefaultDaySummaryBuilder(new GeoDbscanHelper()),
                new DefaultVacationSegmentAssembler(
                    locationHelper: self::locationHelper(),
                    holidayResolver: new NullHolidayResolver(),
                ),
            ),
        ];
        yield 'MonthlyHighlightsClusterStrategy' => [
            MonthlyHighlightsClusterStrategy::class,
            'monthly_highlights',
            null,
        ];
        yield 'NewYearEveClusterStrategy' => [
            NewYearEveClusterStrategy::class,
            'new_year_eve',
            null,
        ];
        yield 'NightlifeEventClusterStrategy' => [
            NightlifeEventClusterStrategy::class,
            'nightlife_event',
            null,
        ];
        yield 'OnThisDayOverYearsClusterStrategy' => [
            OnThisDayOverYearsClusterStrategy::class,
            'on_this_day_over_years',
            null,
        ];
        yield 'OneYearAgoClusterStrategy' => [
            OneYearAgoClusterStrategy::class,
            'one_year_ago',
            null,
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
            null,
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
            null,
        ];
        yield 'SeasonClusterStrategy' => [
            SeasonClusterStrategy::class,
            'season',
            null,
        ];
        yield 'SeasonOverYearsClusterStrategy' => [
            SeasonOverYearsClusterStrategy::class,
            'season_over_years',
            null,
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
            null,
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
            null,
        ];
        yield 'VideoStoriesClusterStrategy' => [
            VideoStoriesClusterStrategy::class,
            'video_stories',
            null,
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
            null,
        ];
    }

    private static function locationHelper(): LocationHelper
    {
        return new LocationHelper();
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
