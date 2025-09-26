<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Weather\WeatherHintProviderInterface;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClusterStrategySmokeTest extends TestCase
{
    /**
     * Ensures every cluster strategy exposes its declared name and gracefully handles empty input.
     *
     * @param class-string<ClusterStrategyInterface> $class
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
            \MagicSunday\Memories\Clusterer\AnniversaryClusterStrategy::class,
            'anniversary',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\AnniversaryClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'AtHomeWeekdayClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\AtHomeWeekdayClusterStrategy::class,
            'at_home_weekday',
            null,
        ];
        yield 'AtHomeWeekendClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\AtHomeWeekendClusterStrategy::class,
            'at_home_weekend',
            null,
        ];
        yield 'BeachOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\BeachOverYearsClusterStrategy::class,
            'beach_over_years',
            null,
        ];
        yield 'BurstClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\BurstClusterStrategy::class,
            'burst',
            null,
        ];
        yield 'CampingOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\CampingOverYearsClusterStrategy::class,
            'camping_over_years',
            null,
        ];
        yield 'CampingTripClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\CampingTripClusterStrategy::class,
            'camping_trip',
            null,
        ];
        yield 'CityscapeNightClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\CityscapeNightClusterStrategy::class,
            'cityscape_night',
            null,
        ];
        yield 'CrossDimensionClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\CrossDimensionClusterStrategy::class,
            'cross_dimension',
            null,
        ];
        yield 'DayAlbumClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\DayAlbumClusterStrategy::class,
            'day_album',
            null,
        ];
        yield 'DeviceSimilarityStrategy' => [
            \MagicSunday\Memories\Clusterer\DeviceSimilarityStrategy::class,
            'device_similarity',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\DeviceSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'DiningOutClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\DiningOutClusterStrategy::class,
            'dining_out',
            null,
        ];
        yield 'FestivalSummerClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\FestivalSummerClusterStrategy::class,
            'festival_summer',
            null,
        ];
        yield 'FirstVisitPlaceClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\FirstVisitPlaceClusterStrategy::class,
            'first_visit_place',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\FirstVisitPlaceClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'GoldenHourClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\GoldenHourClusterStrategy::class,
            'golden_hour',
            null,
        ];
        yield 'HikeAdventureClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\HikeAdventureClusterStrategy::class,
            'hike_adventure',
            null,
        ];
        yield 'HolidayEventClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\HolidayEventClusterStrategy::class,
            'holiday_event',
            null,
        ];
        yield 'KidsBirthdayPartyClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\KidsBirthdayPartyClusterStrategy::class,
            'kids_birthday_party',
            null,
        ];
        yield 'LocationSimilarityStrategy' => [
            \MagicSunday\Memories\Clusterer\LocationSimilarityStrategy::class,
            'location_similarity',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\LocationSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'LongTripClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\LongTripClusterStrategy::class,
            'long_trip',
            null,
        ];
        yield 'MonthlyHighlightsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\MonthlyHighlightsClusterStrategy::class,
            'monthly_highlights',
            null,
        ];
        yield 'MorningCoffeeClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\MorningCoffeeClusterStrategy::class,
            'morning_coffee',
            null,
        ];
        yield 'MuseumOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\MuseumOverYearsClusterStrategy::class,
            'museum_over_years',
            null,
        ];
        yield 'NewYearEveClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\NewYearEveClusterStrategy::class,
            'new_year_eve',
            null,
        ];
        yield 'NightlifeEventClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\NightlifeEventClusterStrategy::class,
            'nightlife_event',
            null,
        ];
        yield 'OnThisDayOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\OnThisDayOverYearsClusterStrategy::class,
            'on_this_day_over_years',
            null,
        ];
        yield 'OneYearAgoClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\OneYearAgoClusterStrategy::class,
            'one_year_ago',
            null,
        ];
        yield 'PanoramaClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\PanoramaClusterStrategy::class,
            'panorama',
            null,
        ];
        yield 'PanoramaOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\PanoramaOverYearsClusterStrategy::class,
            'panorama_over_years',
            null,
        ];
        yield 'PersonCohortClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\PersonCohortClusterStrategy::class,
            'people_cohort',
            null,
        ];
        yield 'PetMomentsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\PetMomentsClusterStrategy::class,
            'pet_moments',
            null,
        ];
        yield 'PhashSimilarityStrategy' => [
            \MagicSunday\Memories\Clusterer\PhashSimilarityStrategy::class,
            'phash_similarity',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\PhashSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'PhotoMotifClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\PhotoMotifClusterStrategy::class,
            'photo_motif',
            null,
        ];
        yield 'PortraitOrientationClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\PortraitOrientationClusterStrategy::class,
            'portrait_orientation',
            null,
        ];
        yield 'RainyDayClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\RainyDayClusterStrategy::class,
            'rainy_day',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\RainyDayClusterStrategy(
                self::weatherStub()
            ),
        ];
        yield 'RoadTripClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\RoadTripClusterStrategy::class,
            'road_trip',
            null,
        ];
        yield 'SeasonClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\SeasonClusterStrategy::class,
            'season',
            null,
        ];
        yield 'SeasonOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\SeasonOverYearsClusterStrategy::class,
            'season_over_years',
            null,
        ];
        yield 'SignificantPlaceClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\SignificantPlaceClusterStrategy::class,
            'significant_place',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\SignificantPlaceClusterStrategy(
                self::locationHelper()
            ),
        ];
        yield 'SnowDayClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\SnowDayClusterStrategy::class,
            'snow_day',
            null,
        ];
        yield 'SnowVacationOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\SnowVacationOverYearsClusterStrategy::class,
            'snow_vacation_over_years',
            null,
        ];
        yield 'SportsEventClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\SportsEventClusterStrategy::class,
            'sports_event',
            null,
        ];
        yield 'SunnyDayClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\SunnyDayClusterStrategy::class,
            'sunny_day',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\SunnyDayClusterStrategy(
                self::weatherStub()
            ),
        ];
        yield 'ThisMonthOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\ThisMonthOverYearsClusterStrategy::class,
            'this_month_over_years',
            null,
        ];
        yield 'TimeSimilarityStrategy' => [
            \MagicSunday\Memories\Clusterer\TimeSimilarityStrategy::class,
            'time_similarity',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\TimeSimilarityStrategy(
                self::locationHelper()
            ),
        ];
        yield 'TransitTravelDayClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\TransitTravelDayClusterStrategy::class,
            'transit_travel_day',
            null,
        ];
        yield 'VideoStoriesClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\VideoStoriesClusterStrategy::class,
            'video_stories',
            null,
        ];
        yield 'WeekendGetawaysOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\WeekendGetawaysOverYearsClusterStrategy::class,
            'weekend_getaways_over_years',
            null,
        ];
        yield 'WeekendTripClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\WeekendTripClusterStrategy::class,
            'weekend_trip',
            static fn (): ClusterStrategyInterface => new \MagicSunday\Memories\Clusterer\WeekendTripClusterStrategy(
                self::locationHelper(),
                null,
                null,
            ),
        ];
        yield 'YearInReviewClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\YearInReviewClusterStrategy::class,
            'year_in_review',
            null,
        ];
        yield 'ZooAquariumClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\ZooAquariumClusterStrategy::class,
            'zoo_aquarium',
            null,
        ];
        yield 'ZooAquariumOverYearsClusterStrategy' => [
            \MagicSunday\Memories\Clusterer\ZooAquariumOverYearsClusterStrategy::class,
            'zoo_aquarium_over_years',
            null,
        ];
    }

    private static function locationHelper(): LocationHelper
    {
        return new LocationHelper();
    }

    private static function weatherStub(): WeatherHintProviderInterface
    {
        return new class implements WeatherHintProviderInterface {
            public function getHint(Media $media): ?array
            {
                return null;
            }
        };
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
