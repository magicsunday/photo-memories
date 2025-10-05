<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Clusterer\Contract\PoiClassifierInterface;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

use function array_keys;
use function assert;
use function count;
use function intdiv;
use function ksort;
use function strtolower;

use const SORT_STRING;

/**
 * @phpstan-type Staypoint array{lat:float,lon:float,start:int,end:int,dwell:int}
 * @phpstan-type BaseLocation array{lat:float,lon:float,distance_km:float,source:string}
 * @phpstan-type DaySummary array{
 *     date: string,
 *     members: list<Media>,
 *     gpsMembers: list<Media>,
 *     maxDistanceKm: float,
 *     distanceSum: float,
 *     distanceCount: int,
 *     avgDistanceKm: float,
 *     travelKm: float,
 *     countryCodes: array<string, true>,
 *     timezoneOffsets: array<int, int>,
 *     localTimezoneIdentifier: string,
 *     localTimezoneOffset: int|null,
 *     tourismHits: int,
 *     poiSamples: int,
 *     tourismRatio: float,
 *     hasAirportPoi: bool,
 *     weekday: int,
 *     photoCount: int,
 *     densityZ: float,
 *     isAwayCandidate: bool,
 *     sufficientSamples: bool,
 *     spotClusters: list<list<Media>>,
 *     spotNoise: list<Media>,
 *     spotCount: int,
 *     spotNoiseSamples: int,
 *     spotDwellSeconds: int,
 *     staypoints: list<Staypoint>,
 *     baseLocation: BaseLocation|null,
 *     baseAway: bool,
 *     awayByDistance: bool,
 *     firstGpsMedia: Media|null,
 *     lastGpsMedia: Media|null,
 *     timezoneIdentifierVotes?: array<string, int>,
 *     isSynthetic: bool,
 * }
 */
/**
 * Initialises per-day summaries from media items.
 */
final readonly class InitializationStage implements DaySummaryStageInterface
{
    /**
     * @param non-empty-string $timezone
     */
    public function __construct(
        private TimezoneResolverInterface $timezoneResolver,
        private PoiClassifierInterface    $poiClassifier,
        private string                    $timezone = 'Europe/Berlin',
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }
    }

    /**
     * @param array<string, DaySummary> $days
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return array<string, DaySummary>
     */
    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        /** @var list<Media> $items */
        $items = $days;

        $summaries = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            assert($takenAt instanceof DateTimeImmutable);

            $mediaTimezone = $this->timezoneResolver->resolveMediaTimezone($media, $takenAt, $home);
            $local         = $takenAt->setTimezone($mediaTimezone);
            $date          = $local->format('Y-m-d');
            $offsetMinutes = intdiv($local->getOffset(), 60);
            $timezoneName  = $mediaTimezone->getName();

            if (!isset($summaries[$date])) {
                $summaries[$date] = [
                    'date'                    => $date,
                    'members'                 => [],
                    'gpsMembers'              => [],
                    'maxDistanceKm'           => 0.0,
                    'distanceSum'             => 0.0,
                    'distanceCount'           => 0,
                    'avgDistanceKm'           => 0.0,
                    'travelKm'                => 0.0,
                    'countryCodes'            => [],
                    'timezoneOffsets'         => [],
                    'localTimezoneIdentifier' => $timezoneName,
                    'localTimezoneOffset'     => $offsetMinutes,
                    'tourismHits'             => 0,
                    'poiSamples'              => 0,
                    'tourismRatio'            => 0.0,
                    'hasAirportPoi'           => false,
                    'weekday'                 => (int) $local->format('N'),
                    'photoCount'              => 0,
                    'densityZ'                => 0.0,
                    'isAwayCandidate'         => false,
                    'sufficientSamples'       => false,
                    'spotClusters'            => [],
                    'spotNoise'               => [],
                    'spotCount'               => 0,
                    'spotNoiseSamples'        => 0,
                    'spotDwellSeconds'        => 0,
                    'staypoints'              => [],
                    'baseLocation'            => null,
                    'baseAway'                => false,
                    'awayByDistance'          => false,
                    'firstGpsMedia'           => null,
                    'lastGpsMedia'            => null,
                    'timezoneIdentifierVotes' => [],
                    'isSynthetic'             => false,
                ];
            }

            $summary = &$summaries[$date];
            $summary['members'][] = $media;
            ++$summary['photoCount'];

            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat !== null && $lon !== null) {
                $summary['gpsMembers'][] = $media;
            }

            if (!isset($summary['timezoneOffsets'][$offsetMinutes])) {
                $summary['timezoneOffsets'][$offsetMinutes] = 0;
            }

            ++$summary['timezoneOffsets'][$offsetMinutes];

            if (!isset($summary['timezoneIdentifierVotes'][$timezoneName])) {
                $summary['timezoneIdentifierVotes'][$timezoneName] = 0;
            }

            ++$summary['timezoneIdentifierVotes'][$timezoneName];

            $location = $media->getLocation();
            if ($location instanceof Location) {
                $countryCode = $location->getCountryCode();
                $country     = $countryCode ?? $location->getCountry();
                if ($country !== null) {
                    $summary['countryCodes'][strtolower($country)] = true;
                }

                if ($this->poiClassifier->isPoiSample($location)) {
                    ++$summary['poiSamples'];
                }

                if ($this->poiClassifier->isTourismPoi($location)) {
                    ++$summary['tourismHits'];
                }

                if ($this->poiClassifier->isTransportPoi($location)) {
                    $summary['hasAirportPoi'] = true;
                }
            }

            unset($summary);
        }

        if ($summaries === []) {
            return [];
        }

        $summaries = $this->ensureContinuousDayRange($summaries);

        foreach ($summaries as &$summary) {
            $offset = $this->timezoneResolver->determineLocalTimezoneOffset($summary['timezoneOffsets'], $home);
            $summary['localTimezoneOffset'] = $offset;
            $summary['localTimezoneIdentifier'] = $this->timezoneResolver->determineLocalTimezoneIdentifier(
                $summary['timezoneIdentifierVotes'],
                $home,
                $offset,
            );

            unset($summary['timezoneIdentifierVotes']);
        }

        unset($summary);

        return $summaries;
    }

    /**
     * @param array<string, DaySummary> $days
     *
     * @return array<string, DaySummary>
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    private function ensureContinuousDayRange(array $days): array
    {
        ksort($days, SORT_STRING);

        $keys = array_keys($days);
        if ($keys === []) {
            return $days;
        }

        $timezone = new DateTimeZone('UTC');

        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $keys[0], $timezone);
        $last  = DateTimeImmutable::createFromFormat('!Y-m-d', $keys[count($keys) - 1], $timezone);

        if ($first === false || $last === false) {
            return $days;
        }

        $cursor = $first;
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            if (!isset($days[$key])) {
                $days[$key] = $this->createSyntheticDaySummary($key);
            }

            $cursor = $cursor->modify('+1 day');
        }

        ksort($days, SORT_STRING);

        return $days;
    }

    /**
     * @return DaySummary
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    private function createSyntheticDaySummary(string $date): array
    {
        $timezone = new DateTimeZone($this->timezone);
        $weekday  = (int) (new DateTimeImmutable($date, $timezone))->format('N');

        return [
            'date'                    => $date,
            'members'                 => [],
            'gpsMembers'              => [],
            'maxDistanceKm'           => 0.0,
            'distanceSum'             => 0.0,
            'distanceCount'           => 0,
            'avgDistanceKm'           => 0.0,
            'travelKm'                => 0.0,
            'countryCodes'            => [],
            'timezoneOffsets'         => [],
            'localTimezoneIdentifier' => $this->timezone,
            'localTimezoneOffset'     => null,
            'tourismHits'             => 0,
            'poiSamples'              => 0,
            'tourismRatio'            => 0.0,
            'hasAirportPoi'           => false,
            'weekday'                 => $weekday,
            'photoCount'              => 0,
            'densityZ'                => 0.0,
            'isAwayCandidate'         => false,
            'sufficientSamples'       => false,
            'spotClusters'            => [],
            'spotNoise'               => [],
            'spotCount'               => 0,
            'spotNoiseSamples'        => 0,
            'spotDwellSeconds'        => 0,
            'staypoints'              => [],
            'baseLocation'            => null,
            'baseAway'                => false,
            'awayByDistance'          => false,
            'firstGpsMedia'           => null,
            'lastGpsMedia'            => null,
            'isSynthetic'             => true,
            'timezoneIdentifierVotes' => [],
        ];
    }
}
