<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Contract\StaypointCandidateProviderInterface;
use MagicSunday\Memories\Service\Metadata\StaypointPlaceHeuristic;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\S2CellId;
use MagicSunday\Memories\Value\PlaceId;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
final class StaypointPlaceHeuristicTest extends TestCase
{
    #[Test]
    public function itDoesNotSupportMediaWithoutGps(): void
    {
        $seed = $this->makeMedia(
            id: 1,
            path: '/library/no-gps.jpg',
            takenAt: '2024-05-01 10:00:00',
        );

        $heuristic = new StaypointPlaceHeuristic(new InMemoryStaypointCandidateProvider());

        self::assertFalse($heuristic->supports($seed->getPath(), $seed));
    }

    #[Test]
    public function itSkipsAssignmentWhenThresholdNotMet(): void
    {
        $seed = $this->makeMedia(
            id: 2,
            path: '/library/short-stay.jpg',
            takenAt: '2024-06-10 14:00:00',
            lat: 48.1201,
            lon: 11.5602,
        );
        $seed->setGeoCell8('48.1200,11.5600');
        $seed->setS2CellId(S2CellId::tokenFromDegrees(48.1201, 11.5602, 12));

        $candidates = [
            $this->makeMedia(
                id: 21,
                path: '/library/short-stay-1.jpg',
                takenAt: '2024-06-10 14:05:00',
                lat: 48.1202,
                lon: 11.5603,
            ),
        ];

        $provider  = new InMemoryStaypointCandidateProvider($candidates);
        $heuristic = new StaypointPlaceHeuristic($provider, minSamples: 3, minDurationMinutes: 45);

        $result = $heuristic->extract($seed->getPath(), $seed);

        self::assertSame($seed, $result);
        self::assertNull($result->getPlaceId());
    }

    #[Test]
    public function itAssignsPlaceIdForDenseStaypoints(): void
    {
        $seed = $this->makeMedia(
            id: 3,
            path: '/library/dense-stay.jpg',
            takenAt: '2024-07-15 12:00:00',
            lat: 48.1505,
            lon: 11.5810,
        );
        $seed->setGeoCell8('48.1500,11.5800');

        $candidates = [
            $this->makeMedia(
                id: 31,
                path: '/library/dense-stay-1.jpg',
                takenAt: '2024-07-15 12:10:00',
                lat: 48.1506,
                lon: 11.5811,
            ),
            $this->makeMedia(
                id: 32,
                path: '/library/dense-stay-2.jpg',
                takenAt: '2024-07-15 12:40:00',
                lat: 48.1507,
                lon: 11.5812,
            ),
            $this->makeMedia(
                id: 33,
                path: '/library/dense-stay-3.jpg',
                takenAt: '2024-07-15 13:05:00',
                lat: 48.1508,
                lon: 11.5813,
            ),
        ];

        $provider  = new InMemoryStaypointCandidateProvider($candidates);
        $heuristic = new StaypointPlaceHeuristic($provider, minSamples: 3, minDurationMinutes: 45, maxSamples: 20, s2Level: 12);

        $result = $heuristic->extract($seed->getPath(), $seed);

        $placeId = $result->getPlaceId();
        self::assertInstanceOf(PlaceId::class, $placeId);
        self::assertSame('staypoint:s2', $placeId->provider);
        self::assertSame(S2CellId::tokenFromDegrees(48.1505, 11.5810, 12), $placeId->identifier);

        $meta = $placeId->meta;
        self::assertSame(4, $meta['samples']);
        self::assertSame(65, $meta['durationMinutes']);
    }

    #[Test]
    public function itKeepsExistingPlaceId(): void
    {
        $seed = $this->makeMedia(
            id: 4,
            path: '/library/existing-place.jpg',
            takenAt: '2024-08-01 09:00:00',
            lat: 48.2001,
            lon: 11.6502,
        );
        $seed->setGeoCell8('48.2000,11.6500');
        $seed->setPlaceId(new PlaceId('manual', 'user-defined'));

        $provider  = new InMemoryStaypointCandidateProvider([
            $this->makeMedia(
                id: 41,
                path: '/library/existing-place-1.jpg',
                takenAt: '2024-08-01 09:30:00',
                lat: 48.2002,
                lon: 11.6503,
            ),
        ]);

        $heuristic = new StaypointPlaceHeuristic($provider);
        $heuristic->extract($seed->getPath(), $seed);

        $placeId = $seed->getPlaceId();
        self::assertInstanceOf(PlaceId::class, $placeId);
        self::assertSame('manual', $placeId->provider);
        self::assertSame('user-defined', $placeId->identifier);
    }
}

/**
 * @internal
 */
final class InMemoryStaypointCandidateProvider implements StaypointCandidateProviderInterface
{
    /**
     * @param list<Media> $candidates
     */
    public function __construct(private array $candidates = [])
    {
    }

    public function findCandidates(Media $seed, int $maxSamples = 500): array
    {
        return $this->candidates;
    }
}
