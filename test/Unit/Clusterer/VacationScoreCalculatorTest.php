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
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * @covers \MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator
 */
final class VacationScoreCalculatorTest extends TestCase
{
    #[Test]
    public function buildDraftScoresMultiDayTripAsVacation(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $calculator = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
        );

        $home = [
            'lat' => 52.5200,
            'lon' => 13.4050,
            'radius_km' => 12.0,
            'country' => 'de',
            'timezone_offset' => 60,
        ];

        $start = new DateTimeImmutable('2024-04-01 09:00:00');
        $days = [];
        for ($i = 0; $i < 3; ++$i) {
            $dayDate = $start->add(new DateInterval('P' . $i . 'D'));
            $members = $this->makeMembersForDay($i, $dayDate);
            $dayKey = $dayDate->format('Y-m-d');
            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 14 + $i,
                poiSamples: 18,
                travelKm: 120.0,
                timezoneOffset: 0,
                hasAirport: $i === 0 || $i === 2,
                spotCount: 2,
                spotDwellSeconds: 7200 + ($i * 1800),
            );
        }

        $draft = $calculator->buildDraft(array_keys($days), $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();
        self::assertSame('vacation', $params['classification']);
        self::assertSame(3, $params['away_days']);
        self::assertTrue($params['airport_transfer']);
        self::assertContains('pt', $params['countries']);
        self::assertGreaterThan(8.0, $params['score']);
        self::assertSame(3, $params['spot_cluster_days']);
        self::assertSame(3, $params['total_days']);
        self::assertGreaterThan(0.0, $params['spot_exploration_bonus']);
    }

    #[Test]
    public function buildDraftInterleavesMembersAcrossDays(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $calculator     = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
        );

        $home = [
            'lat' => 48.2082,
            'lon' => 16.3738,
            'radius_km' => 12.0,
            'country' => 'at',
            'timezone_offset' => 60,
        ];

        $start = new DateTimeImmutable('2024-08-10 08:00:00');
        $days  = [];
        $dayKeys = [];
        $dayCount = 4;
        for ($i = 0; $i < $dayCount; ++$i) {
            $dayDate = $start->add(new DateInterval('P' . $i . 'D'));
            $members = $this->makeMembersForDay($i, $dayDate, 7);
            $dayKey  = $dayDate->format('Y-m-d');
            $dayKeys[] = $dayKey;
            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 12 + $i,
                poiSamples: 16,
                travelKm: 180.0,
                timezoneOffset: 60,
                hasAirport: $i === 0 || $i === $dayCount - 1,
                spotCount: 3,
                spotDwellSeconds: 5400 + ($i * 600),
            );
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $memberIds = $draft->getMembers();
        self::assertGreaterThan(20, count($memberIds));

        $clamped = $this->clampMemberList($memberIds, 20);

        $represented = [];
        foreach ($clamped as $memberId) {
            $dayIndex = intdiv($memberId - 100, 100);
            $represented[$dayIndex] = true;
        }

        $expectedDays = [];
        for ($i = 0; $i < $dayCount; ++$i) {
            $expectedDays[] = $i;
        }

        $actualDays = array_keys($represented);
        sort($actualDays);

        self::assertSame($expectedDays, $actualDays);
    }

    /**
     * @return list<Media>
     */
    private function makeMembersForDay(int $index, DateTimeImmutable $base, int $count = 3): array
    {
        $items = [];
        $baseId = 100 + ($index * 100);
        for ($j = 0; $j < $count; ++$j) {
            $items[] = $this->makeMediaFixture(
                id: $baseId + $j,
                filename: sprintf('trip-day-%d-%d.jpg', $index, $j),
                takenAt: $base->add(new DateInterval('PT' . ($j * 3) . 'H')),
                lat: 38.7223 + ($index * 0.01) + ($j * 0.002),
                lon: -9.1393 + ($index * 0.01) + ($j * 0.002),
                configure: static function (Media $media): void {
                    $media->setTimezoneOffsetMin(0);
                }
            );
        }

        return $items;
    }

    /**
     * @param list<int> $memberIds
     *
     * @return list<int>
     */
    private function clampMemberList(array $memberIds, int $limit): array
    {
        $service = new ClusterPersistenceService(
            $this->createStub(EntityManagerInterface::class),
            250,
            $limit,
        );

        $reflection = new ReflectionClass(ClusterPersistenceService::class);
        $method     = $reflection->getMethod('clampMembers');
        $method->setAccessible(true);

        /** @var list<int> $result */
        $result = $method->invoke($service, $memberIds);

        return $result;
    }

    /**
     * @param list<Media> $members
     * @param list<Media> $gpsMembers
     *
     * @return array<string, mixed>
     */
    private function makeDaySummary(
        string $date,
        int $weekday,
        array $members,
        array $gpsMembers,
        bool $baseAway,
        int $tourismHits,
        int $poiSamples,
        float $travelKm,
        int $timezoneOffset,
        bool $hasAirport,
        int $spotCount,
        int $spotDwellSeconds,
    ): array {
        $first = $gpsMembers[0];
        $last = $gpsMembers[count($gpsMembers) - 1];

        return [
            'date' => $date,
            'members' => $members,
            'gpsMembers' => $gpsMembers,
            'maxDistanceKm' => 180.0,
            'avgDistanceKm' => 95.0,
            'travelKm' => $travelKm,
            'countryCodes' => ['pt' => true],
            'timezoneOffsets' => [$timezoneOffset => count($gpsMembers)],
            'localTimezoneIdentifier' => 'Europe/Lisbon',
            'localTimezoneOffset' => $timezoneOffset,
            'tourismHits' => $tourismHits,
            'poiSamples' => $poiSamples,
            'tourismRatio' => 0.6,
            'hasAirportPoi' => $hasAirport,
            'weekday' => $weekday,
            'photoCount' => count($members),
            'densityZ' => 1.4,
            'isAwayCandidate' => $baseAway,
            'sufficientSamples' => true,
            'spotClusters' => [$gpsMembers],
            'spotNoise' => [],
            'spotCount' => $spotCount,
            'spotNoiseSamples' => 0,
            'spotDwellSeconds' => $spotDwellSeconds,
            'staypoints' => [],
            'baseLocation' => null,
            'baseAway' => $baseAway,
            'awayByDistance' => true,
            'firstGpsMedia' => $first,
            'lastGpsMedia' => $last,
            'isSynthetic' => false,
        ];
    }
}
