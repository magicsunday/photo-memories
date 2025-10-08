<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

use function array_filter;
use function array_map;
use function array_slice;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_convert_case;
use function mb_strtolower;
use function mb_strtoupper;
use function sprintf;
use function trim;
use function usort;

use const MB_CASE_TITLE;

/**
 * Aggregates travel waypoints and event keywords for cluster drafts.
 */
final class TravelWaypointAnnotator
{
    /**
     * Maximum number of waypoint entries returned for a cluster.
     */
    private int $maxWaypoints;

    /**
     * Maximum number of event keyword entries returned for a cluster.
     */
    private int $maxEventKeywords;

    public function __construct(int $maxWaypoints = 5, int $maxEventKeywords = 5)
    {
        $this->maxWaypoints     = $maxWaypoints > 0 ? $maxWaypoints : 5;
        $this->maxEventKeywords = $maxEventKeywords > 0 ? $maxEventKeywords : 5;
    }

    /**
     * @param list<Media> $members
     *
     * @return array{
     *     waypoints: list<array{
     *         label: string,
     *         city: ?string,
     *         region: ?string,
     *         country: ?string,
     *         countryCode: ?string,
     *         count: int
     *     }>,
     *     events: list<array{label: string, count: int}>
     * }
     */
    public function annotate(array $members): array
    {
        $waypointCounters = [];
        $eventCounters    = [];

        foreach ($members as $media) {
            $location = $media->getLocation();
            if (!$location instanceof Location) {
                continue;
            }

            $key = $this->buildWaypointKey($location);
            if ($key === null) {
                continue;
            }

            $timestamp = $media->getTakenAt()?->getTimestamp();
            $waypointCounters[$key] ??= [
                'label'      => $this->resolveLabel($location),
                'city'       => $this->normaliseComponent($location->getCity()),
                'region'     => $this->resolveRegion($location),
                'country'    => $this->normaliseComponent($location->getCountry()),
                'countryCode'=> $this->normaliseCountryCode($location->getCountryCode()),
                'count'      => 0,
                'firstSeen'  => $timestamp ?? PHP_INT_MAX,
            ];

            ++$waypointCounters[$key]['count'];

            if (is_int($timestamp) && $timestamp < $waypointCounters[$key]['firstSeen']) {
                $waypointCounters[$key]['firstSeen'] = $timestamp;
            }

            $this->collectEventKeywords($location, $eventCounters);
        }

        $waypoints = array_map(
            static function (array $entry): array {
                return [
                    'label'       => $entry['label'],
                    'city'        => $entry['city'],
                    'region'      => $entry['region'],
                    'country'     => $entry['country'],
                    'countryCode' => $entry['countryCode'],
                    'count'       => $entry['count'],
                    'firstSeen'   => $entry['firstSeen'],
                ];
            },
            $waypointCounters
        );

        usort(
            $waypoints,
            static function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];
                if ($countComparison !== 0) {
                    return $countComparison;
                }

                $firstSeenComparison = $left['firstSeen'] <=> $right['firstSeen'];
                if ($firstSeenComparison !== 0) {
                    return $firstSeenComparison;
                }

                return strcmp($left['label'], $right['label']);
            }
        );

        $waypoints = array_slice($waypoints, 0, $this->maxWaypoints);

        $waypoints = array_map(
            static function (array $entry): array {
                unset($entry['firstSeen']);

                return $entry;
            },
            $waypoints
        );

        $events = $this->buildEventList($eventCounters);

        return [
            'waypoints' => $waypoints,
            'events'    => $events,
        ];
    }

    private function buildWaypointKey(Location $location): ?string
    {
        $label = $this->resolveLabel($location);
        if ($label === null) {
            return null;
        }

        $components = array_filter([
            $label,
            $this->normaliseComponent($location->getCity()),
            $this->resolveRegion($location),
            $this->normaliseComponent($location->getCountry()),
        ], static fn (?string $value): bool => is_string($value) && $value !== '');

        if ($components === []) {
            return null;
        }

        return mb_strtolower(implode('|', $components));
    }

    private function resolveLabel(Location $location): ?string
    {
        $candidates = [
            $location->getCity(),
            $location->getSuburb(),
            $location->getDisplayName(),
        ];

        foreach ($candidates as $candidate) {
            $normalised = $this->normaliseComponent($candidate);
            if ($normalised !== null && $normalised !== '') {
                return $normalised;
            }
        }

        return null;
    }

    private function resolveRegion(Location $location): ?string
    {
        $region = $location->getState();
        if ($region === null || $region === '') {
            $region = $location->getCounty();
        }

        return $this->normaliseComponent($region);
    }

    private function normaliseComponent(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');
        if ($lower === $trimmed) {
            return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
        }

        return $trimmed;
    }

    private function normaliseCountryCode(?string $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }

        $trimmed = trim($code);
        if ($trimmed === '') {
            return null;
        }

        return mb_strtoupper($trimmed, 'UTF-8');
    }

    /**
     * @param array<string, int> $eventCounters
     */
    private function collectEventKeywords(Location $location, array &$eventCounters): void
    {
        $pois = $location->getPois();
        if (!is_array($pois)) {
            return;
        }

        foreach ($pois as $poi) {
            if (!is_array($poi)) {
                continue;
            }

            $tags = $poi['tags'] ?? null;
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tagKey => $tagValue) {
                if (!is_string($tagKey) || !is_string($tagValue)) {
                    continue;
                }

                $keyword = $this->mapEventKeyword($tagKey, $tagValue);
                if ($keyword === null) {
                    continue;
                }

                $eventCounters[$keyword] = ($eventCounters[$keyword] ?? 0) + 1;
            }
        }
    }

    private function mapEventKeyword(string $key, string $value): ?string
    {
        $keyLower   = mb_strtolower(trim($key), 'UTF-8');
        $valueLower = mb_strtolower(trim($value), 'UTF-8');

        if ($keyLower === '' || $valueLower === '') {
            return null;
        }

        $directMappings = [
            'festival' => 'Festival',
            'concert'  => 'Konzert',
            'concerts' => 'Konzert',
            'event'    => 'Event',
            'sport'    => 'Sport',
            'sports'   => 'Sport',
        ];

        if (isset($directMappings[$keyLower])) {
            if ($keyLower === 'event' && $valueLower !== 'yes') {
                return null;
            }

            return $directMappings[$keyLower];
        }

        if ($keyLower === 'amenity') {
            $amenityMappings = [
                'theatre'      => 'Theater',
                'arts_centre'  => 'Kulturzentrum',
                'cinema'       => 'Kino',
                'stadium'      => 'Stadion',
                'community_centre' => 'Treffpunkt',
            ];

            if (isset($amenityMappings[$valueLower])) {
                return $amenityMappings[$valueLower];
            }

            return $this->normaliseComponent($valueLower);
        }

        if (in_array($keyLower, ['event:genre', 'event:category'], true)) {
            return $this->normaliseComponent($valueLower);
        }

        if ($keyLower === 'leisure' && $valueLower === 'stadium') {
            return 'Stadion';
        }

        return null;
    }

    /**
     * @param array<string, int> $eventCounters
     *
     * @return list<array{label: string, count: int}>
     */
    private function buildEventList(array $eventCounters): array
    {
        $events = [];
        foreach ($eventCounters as $label => $count) {
            if (!is_string($label) || $label === '' || $count < 1) {
                continue;
            }

            $events[] = [
                'label' => $label,
                'count' => $count,
            ];
        }

        usort(
            $events,
            static function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];
                if ($countComparison !== 0) {
                    return $countComparison;
                }

                return strcmp($left['label'], $right['label']);
            }
        );

        return array_slice($events, 0, $this->maxEventKeywords);
    }
}
