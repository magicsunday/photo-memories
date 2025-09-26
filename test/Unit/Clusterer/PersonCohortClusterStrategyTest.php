<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\PersonCohortClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        $start = new DateTimeImmutable('2024-01-05 12:00:00');
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

        $start = new DateTimeImmutable('2024-02-01 10:00:00');
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

    private function createPersonMedia(int $id, DateTimeImmutable $takenAt, array $persons): Media
    {
        $media = new PersonTagMedia(
            path: __DIR__ . "/fixtures/cohort-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 2048,
            personIds: $persons,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat(52.5);
        $media->setGpsLon(13.4);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}

final class PersonTagMedia extends Media
{
    /** @var list<int> */
    private array $personIds;

    /**
     * @param list<int> $personIds
     */
    public function __construct(string $path, string $checksum, int $size, array $personIds)
    {
        parent::__construct($path, $checksum, $size);
        $this->personIds = $personIds;
    }

    /**
     * @return list<int>
     */
    public function getPersonIds(): array
    {
        return $this->personIds;
    }
}
