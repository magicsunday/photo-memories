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
use MagicSunday\Memories\Clusterer\PersonCohortClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\PersonSignatureHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class PersonCohortClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersStablePersonGroupWithinWindow(): void
    {
        $strategy = new PersonCohortClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minPersons: 2,
            minItemsTotal: 5,
            windowDays: 7,
            personSignatureHelper: new PersonSignatureHelper(),
        );

        $start = new DateTimeImmutable('2024-01-05 12:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
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

        $params = $cluster->getParams();
        self::assertSame([1, 2, 3], $params['persons']);
        self::assertArrayNotHasKey('person_labels', $params);
        self::assertSame('group_portrait', $params['quality_profile']);
        self::assertArrayHasKey('quality_avg', $params);
        self::assertGreaterThan(0.0, $params['quality_avg']);
    }

    #[Test]
    public function requiresMinimumPersons(): void
    {
        $strategy = new PersonCohortClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minPersons: 3,
            minItemsTotal: 5,
            windowDays: 7,
            personSignatureHelper: new PersonSignatureHelper(),
        );

        $start = new DateTimeImmutable('2024-02-01 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->createPersonMedia(
                1900 + $i,
                $start->add(new DateInterval('P' . $i . 'D')),
                [1, 2],
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function includesMediaWithImportedPersonsMetadata(): void
    {
        $strategy = new PersonCohortClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minPersons: 2,
            minItemsTotal: 4,
            windowDays: 5,
            personSignatureHelper: new PersonSignatureHelper(),
        );

        $start = new DateTimeImmutable('2024-03-15 08:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 4; ++$i) {
            $items[] = $this->makeMediaFixture(
                id: 2000 + $i,
                filename: sprintf('iptc-%d.jpg', $i),
                takenAt: $start->add(new DateInterval('P' . $i . 'D')),
                lat: 48.1,
                lon: 11.6,
                configure: static function (Media $media): void {
                    $media->setPersons(['Alice', ' Bob ', 'alice']);
                },
            );
        }

        // Noise with insufficient or different persons metadata
        $items[] = $this->makeMediaFixture(
            id: 2050,
            filename: 'iptc-noise-a.jpg',
            takenAt: $start,
            lat: 48.1,
            lon: 11.6,
            configure: static function (Media $media): void {
                $media->setPersons(['Alice']);
            },
        );

        $items[] = $this->makeMediaFixture(
            id: 2051,
            filename: 'iptc-noise-b.jpg',
            takenAt: $start,
            lat: 48.1,
            lon: 11.6,
            configure: static function (Media $media): void {
                $media->setPersons(['Charlie', 'Dana']);
            },
        );

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame([2000, 2001, 2002, 2003], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertArrayHasKey('persons', $params);
        self::assertSame(['Alice', 'Bob'], $params['person_labels']);
    }

    /**
     * @param list<int> $persons
     */
    private function createPersonMedia(int $id, DateTimeImmutable $takenAt, array $persons): Media
    {
        return $this->makePersonTaggedMediaFixture(
            id: $id,
            filename: sprintf('cohort-%d.jpg', $id),
            personIds: $persons,
            takenAt: $takenAt,
            lat: 52.5,
            lon: 13.4,
            configure: static function (Media $media): void {
                $media->setWidth(3600);
                $media->setHeight(2400);
                $media->setSharpness(0.6);
                $media->setIso(200);
                $media->setBrightness(0.57);
                $media->setContrast(0.68);
            },
        );
    }
}
