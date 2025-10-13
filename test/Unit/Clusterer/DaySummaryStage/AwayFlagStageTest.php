<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\DaySummaryStage;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Contract\BaseLocationResolverInterface;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AwayFlagStageTest extends TestCase
{
    #[Test]
    public function marksAwayDaysBasedOnBaseLocationAndDistance(): void
    {
        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin');
        $gpsStage         = new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), 1.0, 3, 3);
        $densityStage     = new DensityStage();
        $awayStage        = new AwayFlagStage($timezoneResolver, new BaseLocationResolver());

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5200,
                'lon'           => 13.4050,
                'radius_km'     => 12.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ]],
        ];

        $berlin = $this->makeLocation('berlin', 'Berlin, Germany', 52.5200, 13.4050, country: 'Germany');
        $nyc    = $this->makeLocation(
            'nyc',
            'New York, USA',
            40.7128,
            -74.0060,
            country: 'United States',
            configure: static function (Location $location): void {
                $location->setCategory('tourism');
                $location->setType('attraction');
            },
        );

        $items     = [];
        $homeStart = new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $homeStart->add(new DateInterval('PT' . ($i * 2) . 'H'));
            $items[]   = $this->makeMediaFixture(
                10 + $i,
                sprintf('home-%d.jpg', $i),
                $timestamp,
                $berlin->getLat() + ($i * 0.0002),
                $berlin->getLon() + ($i * 0.0002),
                $berlin,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                },
            );
        }

        $awayStart = new DateTimeImmutable('2024-06-02 12:00:00', new DateTimeZone('America/New_York'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $awayStart->add(new DateInterval('PT' . ($i * 3) . 'H'));
            $items[]   = $this->makeMediaFixture(
                20 + $i,
                sprintf('away-%d.jpg', $i),
                $timestamp,
                $nyc->getLat() + ($i * 0.01),
                $nyc->getLon() + ($i * 0.01),
                $nyc,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(-240);
                },
            );
        }

        $initial = $initialStage->process($items, $home);
        $gps     = $gpsStage->process($initial, $home);
        $dense   = $densityStage->process($gps, $home);
        $result  = $awayStage->process($dense, $home);

        self::assertFalse($result['2024-06-01']['awayByDistance']);
        self::assertTrue($result['2024-06-02']['baseAway']);
    }

    #[Test]
    public function treatsSecondaryHomeCenterAsHome(): void
    {
        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin');
        $gpsStage         = new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), 1.0, 3, 3);
        $densityStage     = new DensityStage();
        $awayStage        = new AwayFlagStage($timezoneResolver, new BaseLocationResolver());

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5200,
                'lon'           => 13.4050,
                'radius_km'     => 12.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ], [
                'lat'           => 48.1371,
                'lon'           => 11.5754,
                'radius_km'     => 8.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ]],
        ];

        $munich = $this->makeLocation('munich', 'München, Deutschland', 48.1371, 11.5754, country: 'Germany');

        $items   = [];
        $dayBase = new DateTimeImmutable('2024-07-10 09:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 4; ++$i) {
            $items[] = $this->makeMediaFixture(
                200 + $i,
                sprintf('alt-home-%d.jpg', $i),
                $dayBase->add(new DateInterval('PT' . ($i * 2) . 'H')),
                $munich->getLat() + ($i * 0.0001),
                $munich->getLon() + ($i * 0.0001),
                $munich,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                },
            );
        }

        $initial = $initialStage->process($items, $home);
        $gps     = $gpsStage->process($initial, $home);
        $dense   = $densityStage->process($gps, $home);
        $result  = $awayStage->process($dense, $home);

        self::assertArrayHasKey('2024-07-10', $result);
        self::assertFalse($result['2024-07-10']['baseAway']);
        self::assertFalse($result['2024-07-10']['awayByDistance']);
    }

    #[Test]
    public function flagsDayWhenNextDominantFarAndNightMissingHomeStaypoint(): void
    {
        $timezone = new DateTimeZone('Europe/Berlin');

        $timezoneResolver = new class($timezone) implements TimezoneResolverInterface
        {
            public function __construct(private DateTimeZone $timezone)
            {
            }

            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                return $this->timezone;
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                return $this->timezone;
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return (int) ($home['timezone_offset'] ?? 0);
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                return 'Europe/Berlin';
            }
        };

        $baseLocationResolver = new class() implements BaseLocationResolverInterface
        {
            public function resolve(array $summary, ?array $nextSummary, array $home, DateTimeZone $timezone): ?array
            {
                return null;
            }
        };

        $awayStage = new AwayFlagStage(
            $timezoneResolver,
            $baseLocationResolver,
            nextDayDominantDistanceFactor: 1.5,
            nightWindowStartHour: 22,
            nightWindowEndHour: 6,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5200,
                'lon'           => 13.4050,
                'radius_km'     => 12.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ]],
        ];

        $dayStayStart = new DateTimeImmutable('2024-08-01 08:00:00', $timezone);
        $dayStayEnd   = new DateTimeImmutable('2024-08-01 21:30:00', $timezone);
        $nextStayStart = new DateTimeImmutable('2024-08-02 07:00:00', $timezone);
        $nextStayEnd   = new DateTimeImmutable('2024-08-02 18:00:00', $timezone);

        $days = [
            '2024-08-01' => [
                'date'            => '2024-08-01',
                'staypoints'      => [[
                    'lat'   => 48.2082,
                    'lon'   => 16.3738,
                    'start' => $dayStayStart->getTimestamp(),
                    'end'   => $dayStayEnd->getTimestamp(),
                ]],
                'dominantStaypoints' => [],
                'gpsMembers'         => [],
                'avgDistanceKm'      => 5.0,
                'baseAway'           => false,
                'awayByDistance'     => false,
                'isSynthetic'        => false,
            ],
            '2024-08-02' => [
                'date'            => '2024-08-02',
                'staypoints'      => [[
                    'lat'   => 41.3851,
                    'lon'   => 2.1734,
                    'start' => $nextStayStart->getTimestamp(),
                    'end'   => $nextStayEnd->getTimestamp(),
                ]],
                'dominantStaypoints' => [[
                    'key'          => 'stay-2024-08-02',
                    'lat'          => 41.3851,
                    'lon'          => 2.1734,
                    'start'        => $nextStayStart->getTimestamp(),
                    'end'          => $nextStayStart->modify('+3 hours')->getTimestamp(),
                    'dwellSeconds' => 10800,
                    'memberCount'  => 3,
                ]],
                'gpsMembers'         => [],
                'avgDistanceKm'      => 10.0,
                'baseAway'           => false,
                'awayByDistance'     => false,
                'isSynthetic'        => false,
            ],
        ];

        $result = $awayStage->process($days, $home);

        self::assertTrue($result['2024-08-01']['baseAway']);
        self::assertFalse($result['2024-08-02']['baseAway']);
    }

    #[Test]
    public function treatsStaypointAlignedWithExpiredHomeCenterAsAway(): void
    {
        $timezone = new DateTimeZone('Europe/Berlin');

        $timezoneResolver = new class($timezone) implements TimezoneResolverInterface
        {
            public function __construct(private DateTimeZone $timezone)
            {
            }

            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                return $this->timezone;
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                return $this->timezone;
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return (int) ($home['timezone_offset'] ?? 0);
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                return 'Europe/Berlin';
            }
        };

        $baseLocationResolver = new class() implements BaseLocationResolverInterface
        {
            public function resolve(array $summary, ?array $nextSummary, array $home, DateTimeZone $timezone): ?array
            {
                return null;
            }
        };

        $awayStage = new AwayFlagStage(
            $timezoneResolver,
            $baseLocationResolver,
            nextDayDominantDistanceFactor: 1.5,
            nightWindowStartHour: 22,
            nightWindowEndHour: 6,
        );

        $expiredUntil = (new DateTimeImmutable('2024-07-09 21:59:59', $timezone))->getTimestamp();

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5200,
                'lon'           => 13.4050,
                'radius_km'     => 12.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ], [
                'lat'         => 48.1371,
                'lon'         => 11.5754,
                'radius_km'   => 8.0,
                'valid_from'  => (new DateTimeImmutable('2023-01-01 00:00:00', $timezone))->getTimestamp(),
                'valid_until' => $expiredUntil,
            ]],
        ];

        $stayStart     = new DateTimeImmutable('2024-07-10 22:30:00', $timezone);
        $stayEnd       = new DateTimeImmutable('2024-07-11 01:00:00', $timezone);
        $nextStayStart = new DateTimeImmutable('2024-07-11 08:00:00', $timezone);
        $nextStayEnd   = new DateTimeImmutable('2024-07-11 12:00:00', $timezone);

        $days = [
            '2024-07-10' => [
                'date'                => '2024-07-10',
                'staypoints'          => [[
                    'lat'   => 48.1371,
                    'lon'   => 11.5754,
                    'start' => $stayStart->getTimestamp(),
                    'end'   => $stayEnd->getTimestamp(),
                ]],
                'dominantStaypoints' => [],
                'gpsMembers'         => [],
                'avgDistanceKm'      => 0.0,
                'baseAway'           => false,
                'awayByDistance'     => false,
                'isSynthetic'        => false,
            ],
            '2024-07-11' => [
                'date'                => '2024-07-11',
                'staypoints'          => [[
                    'lat'   => 41.3851,
                    'lon'   => 2.1734,
                    'start' => $nextStayStart->getTimestamp(),
                    'end'   => $nextStayEnd->getTimestamp(),
                ]],
                'dominantStaypoints' => [[
                    'key'          => 'stay-2024-07-11',
                    'lat'          => 41.3851,
                    'lon'          => 2.1734,
                    'start'        => $nextStayStart->getTimestamp(),
                    'end'          => $nextStayEnd->getTimestamp(),
                    'dwellSeconds' => $nextStayEnd->getTimestamp() - $nextStayStart->getTimestamp(),
                    'memberCount'  => 4,
                ]],
                'gpsMembers'         => [],
                'avgDistanceKm'      => 10.0,
                'baseAway'           => false,
                'awayByDistance'     => false,
                'isSynthetic'        => false,
            ],
        ];

        $result = $awayStage->process($days, $home);

        self::assertTrue($result['2024-07-10']['baseAway']);
        self::assertFalse($result['2024-07-11']['baseAway']);
    }
}
