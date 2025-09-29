<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\SeasonOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class SeasonOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function mergesSeasonAcrossYears(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            minYears: 3,
            minItemsPerSeason: 6,
        );

        $mediaItems = [
            $this->createMedia(1, '2019-07-01 08:00:00'),
            $this->createMedia(2, '2019-07-05 09:00:00'),
            $this->createMedia(3, '2020-08-10 10:00:00'),
            $this->createMedia(4, '2020-08-11 11:00:00'),
            $this->createMedia(5, '2021-06-15 12:00:00'),
            $this->createMedia(6, '2021-06-18 13:00:00'),
            $this->createMedia(7, '2021-12-05 14:00:00'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('season_over_years', $cluster->getAlgorithm());
        self::assertSame('Sommer im Laufe der Jahre', $cluster->getParams()['label']);
        self::assertSame([1, 2, 3, 4, 5, 6], $cluster->getMembers());
        self::assertContains(2021, $cluster->getParams()['years']);
    }

    #[Test]
    public function requiresMinimumYears(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            minYears: 4,
            minItemsPerSeason: 5,
        );

        $mediaItems = [
            $this->createMedia(11, '2019-04-01 08:00:00'),
            $this->createMedia(12, '2020-04-02 09:00:00'),
            $this->createMedia(13, '2021-04-03 10:00:00'),
            $this->createMedia(14, '2021-04-04 11:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('season-over-years-%d.jpg', $id),
            takenAt: $takenAt,
        );
    }

}
