<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\PersonCohortClusterStrategy;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;

final class PersonCohortClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersStablePersonGroupWithinWindow(): void
    {
        $strategy = new PersonCohortClusterStrategy(
            minPersons: 2,
            minItemsTotal: 5,
            windowDays: 7,
        );

        $start = new DateTimeImmutable('2024-01-05 12:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createPersonMedia(
                1700 + $i,
                $start->add(new DateInterval('P' . $i . 'D')),
                [1, 2, 3],
            );
        }

        // noise with different cohort should be ignored
        $items[] = $this->createPersonMedia(1800, $start, [1]);
        $items[] = $this->createPersonMedia(1801, $start, [4, 5]);

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('people_cohort', $cluster->getAlgorithm());
        self::assertSame([1700, 1701, 1702, 1703, 1704], $cluster->getMembers());
    }

    #[Test]
    public function requiresMinimumPersons(): void
    {
        $strategy = new PersonCohortClusterStrategy(
            minPersons: 3,
            minItemsTotal: 5,
            windowDays: 7,
        );

        $start = new DateTimeImmutable('2024-02-01 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createPersonMedia(
                1900 + $i,
                $start->add(new DateInterval('P' . $i . 'D')),
                [1, 2],
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    /**
     * @param list<int> $persons
     */
    private function createPersonMedia(int $id, DateTimeImmutable $takenAt, array $persons): Media
    {
        return $this->makePersonTaggedMediaFixture(
            id: $id,
            filename: "cohort-{$id}.jpg",
            personIds: $persons,
            takenAt: $takenAt,
            lat: 52.5,
            lon: 13.4,
        );
    }
}
