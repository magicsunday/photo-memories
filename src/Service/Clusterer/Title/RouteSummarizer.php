<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Title;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Utility\MediaMath;
use NumberFormatter;

use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_strtolower;
use function sprintf;
use function trim;
use function usort;

use const PHP_INT_MAX;

/**
 * Derives a compact travel route summary from cluster metadata.
 */
final class RouteSummarizer
{
    public function __construct(
        private readonly int $minStops = 3,
        private readonly int $maxStops = 5,
    ) {
    }

    public function summarize(ClusterDraft $cluster, string $locale = 'de'): ?RouteSummary
    {
        $params = $cluster->getParams();
        $waypoints = $params['travel_waypoints'] ?? null;
        if (!is_array($waypoints) || $waypoints === []) {
            return null;
        }

        $uniqueStops = $this->collectUniqueStops($waypoints);
        if (count($uniqueStops) < $this->minStops) {
            return null;
        }

        $chronological = $uniqueStops;
        usort(
            $chronological,
            static fn (array $left, array $right): int => $left['first_seen_at'] <=> $right['first_seen_at']
        );

        $first = $chronological[0];
        $last  = $chronological[count($chronological) - 1];

        $byCount = $uniqueStops;
        usort(
            $byCount,
            static function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];
                if ($countComparison !== 0) {
                    return $countComparison;
                }

                return $left['first_seen_at'] <=> $right['first_seen_at'];
            }
        );

        $selected = [];
        $selectedKeys = [];

        $this->addStop($selected, $selectedKeys, $first);
        $this->addStop($selected, $selectedKeys, $last);

        foreach ($byCount as $candidate) {
            if (count($selected) >= $this->maxStops) {
                break;
            }

            $this->addStop($selected, $selectedKeys, $candidate);
        }

        if (count($selected) < $this->minStops) {
            foreach ($chronological as $candidate) {
                if (count($selected) >= $this->minStops) {
                    break;
                }

                $this->addStop($selected, $selectedKeys, $candidate);
            }
        }

        if (count($selected) < $this->minStops) {
            return null;
        }

        usort(
            $selected,
            static fn (array $left, array $right): int => $left['first_seen_at'] <=> $right['first_seen_at']
        );

        $stops = array_map(static fn (array $stop): string => $stop['label'], $selected);
        $route = implode(' → ', $stops);

        $distanceKm = $this->calculateDistance($selected);
        $distanceLabel = $this->formatDistanceLabel($distanceKm, $locale);

        $stopCount = count($selected);
        $legCount  = max(0, $stopCount - 1);

        $stopLabel = $stopCount === 1 ? '1 Stopp' : sprintf('%d Stopps', $stopCount);
        $legLabel  = $legCount === 1 ? '1 Etappe' : sprintf('%d Etappen', $legCount);

        $metricsLabel = $this->joinParts([$distanceLabel, $stopLabel]);

        return new RouteSummary(
            routeLabel: $route,
            stopCount: $stopCount,
            legCount: $legCount,
            distanceKm: $distanceKm,
            distanceLabel: $distanceLabel,
            stopLabel: $stopLabel,
            legLabel: $legLabel,
            metricsLabel: $metricsLabel,
            stops: $stops,
        );
    }

    /**
     * @param list<array<string, mixed>> $waypoints
     *
     * @return list<array{label:string,lat:float,lon:float,count:int,first_seen_at:int}>
     */
    private function collectUniqueStops(array $waypoints): array
    {
        $stops = [];

        foreach ($waypoints as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = $this->resolveLabel($entry);
            if ($label === '') {
                continue;
            }

            $lat = $this->numericOrNull($entry['lat'] ?? null);
            $lon = $this->numericOrNull($entry['lon'] ?? null);
            if ($lat === null || $lon === null) {
                continue;
            }

            $count = $this->intOrZero($entry['count'] ?? null);
            if ($count <= 0) {
                continue;
            }

            $firstSeen = $this->intOrMax($entry['first_seen_at'] ?? null);

            $key = mb_strtolower($label, 'UTF-8');
            if (!array_key_exists($key, $stops)) {
                $stops[$key] = [
                    'label'         => $label,
                    'lat'           => $lat,
                    'lon'           => $lon,
                    'count'         => $count,
                    'first_seen_at' => $firstSeen,
                ];

                continue;
            }

            $existing = $stops[$key];
            $stops[$key]['count'] = $existing['count'] + $count;
            if ($firstSeen < $existing['first_seen_at']) {
                $stops[$key]['first_seen_at'] = $firstSeen;
            }
        }

        return array_values($stops);
    }

    private function addStop(array &$selected, array &$selectedKeys, array $candidate): void
    {
        $key = mb_strtolower($candidate['label'], 'UTF-8');
        if (in_array($key, $selectedKeys, true)) {
            return;
        }

        $selected[]    = $candidate;
        $selectedKeys[] = $key;
    }

    /**
     * @param list<array{lat:float,lon:float}> $stops
     */
    private function calculateDistance(array $stops): float
    {
        $total = 0.0;

        for ($i = 1, $c = count($stops); $i < $c; ++$i) {
            $prev = $stops[$i - 1];
            $curr = $stops[$i];

            $meters = MediaMath::haversineDistanceInMeters(
                $prev['lat'],
                $prev['lon'],
                $curr['lat'],
                $curr['lon'],
            );

            $total += $meters / 1000.0;
        }

        return $total;
    }

    private function formatDistanceLabel(float $distanceKm, string $locale): string
    {
        if ($distanceKm <= 0.0) {
            return '';
        }

        $approx = $this->approximateDistance($distanceKm);

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        if ($approx >= 5.0) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        } else {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 1);
        }

        $formatted = $formatter->format($approx);
        if (!is_string($formatted)) {
            $formatted = (string) $approx;
        }

        return sprintf('ca. %s km', $formatted);
    }

    private function approximateDistance(float $distanceKm): float
    {
        if ($distanceKm >= 100.0) {
            return (float) (round($distanceKm / 10) * 10);
        }

        if ($distanceKm >= 20.0) {
            return (float) (round($distanceKm / 5) * 5);
        }

        if ($distanceKm >= 5.0) {
            return (float) round($distanceKm);
        }

        return round($distanceKm, 1);
    }

    private function resolveLabel(array $entry): string
    {
        $candidates = ['label', 'city', 'region', 'country'];

        foreach ($candidates as $key) {
            $value = $entry[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '') {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function intOrZero(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function intOrMax(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return PHP_INT_MAX;
    }

    /**
     * @param list<string> $parts
     */
    private function joinParts(array $parts): string
    {
        $filtered = [];

        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }

            $trimmed = trim($part);
            if ($trimmed !== '') {
                $filtered[] = $trimmed;
            }
        }

        return $filtered === [] ? '' : implode(' • ', $filtered);
    }
}
