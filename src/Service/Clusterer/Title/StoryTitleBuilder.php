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

use function array_key_exists;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function arsort;
use function count;
use function hash;
use function implode;
use function in_array;
use function intval;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_convert_case;
use function mb_strtolower;
use function round;
use function sprintf;
use function substr;
use function trim;

use const MB_CASE_TITLE;
use const PHP_INT_MAX;

/**
 * Builds human readable story titles and subtitles for vacation clusters.
 */
final class StoryTitleBuilder
{
    public function __construct(
        private readonly RouteSummarizer $routeSummarizer,
        private readonly LocalizedDateFormatter $dateFormatter,
        private readonly string $preferredLocale = 'de',
    ) {
    }

    /**
     * Generates title and subtitle strings for the given cluster.
     *
     * @return array{title: string, subtitle: string}
     */
    public function build(ClusterDraft $cluster, ?string $locale = null, ?RouteSummary $summary = null): array
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $summary ??= $this->routeSummarizer->summarize($cluster, $resolvedLocale);

        $params = $cluster->getParams();

        $title = $this->buildTitle($params, $summary);
        $subtitle = $this->buildSubtitle($params, $summary, $resolvedLocale);

        return [
            'title' => $title,
            'subtitle' => $subtitle,
        ];
    }

    private function buildTitle(array $params, ?RouteSummary $summary): string
    {
        $base = '';
        if ($summary instanceof RouteSummary && $summary->routeLabel !== '') {
            $base = $summary->routeLabel;
        } else {
            $classification = $this->stringOrEmpty($params['classification_label'] ?? null);
            if ($classification === '') {
                $classification = 'Reise';
            }

            $location = $this->resolvePrimaryLocationLabel($params);
            if ($location !== '') {
                $base = $classification . ' – ' . $location;
            } else {
                $base = $classification;
            }
        }

        $companions = $this->formatCompanionNames($params);
        if ($companions !== '') {
            if ($base !== '') {
                return $base . ' mit ' . $companions;
            }

            return 'Mit ' . $companions;
        }

        if ($base === '') {
            return 'Reise';
        }

        return $base;
    }

    private function buildSubtitle(array $params, ?RouteSummary $summary, string $locale): string
    {
        $parts = [];

        if ($summary instanceof RouteSummary) {
            if ($summary->metricsLabel !== '') {
                $parts[] = $summary->metricsLabel;
            } else {
                $distance = $summary->distanceLabel;
                if ($distance !== '') {
                    $parts[] = $distance;
                }

                $stop = $summary->stopLabel;
                if ($stop !== '') {
                    $parts[] = $stop;
                }
            }
        }

        $parts[] = $this->resolveDateLabel($params, $locale);
        $parts[] = $this->formatPeopleShare($params);

        $subtitle = $this->joinParts($parts);
        if ($subtitle !== '') {
            return $subtitle;
        }

        $fallback = $this->resolveDateLabel($params, $locale);
        if ($fallback !== '') {
            return $fallback;
        }

        return 'Personenanteil: 0 %';
    }

    private function resolveDateLabel(array $params, string $locale): string
    {
        $explicit = $this->stringOrEmpty($params['date_range'] ?? null);
        if ($explicit !== '') {
            return $explicit;
        }

        $range = $params['time_range'] ?? null;
        if (is_array($range)) {
            $formatted = $this->dateFormatter->formatRange($range, $locale);
            if ($formatted !== '') {
                return $formatted;
            }
        }

        $start = $this->dateFormatter->formatDate($params['start_date'] ?? null, $locale);
        $end   = $this->dateFormatter->formatDate($params['end_date'] ?? null, $locale);

        return $this->joinParts([$start, $end], ' – ');
    }

    private function formatPeopleShare(array $params): string
    {
        $ratio = $this->numericOrNull($params['people_ratio'] ?? null);
        if ($ratio === null) {
            $ratio = $this->numericOrNull($params['cohort_presence_ratio'] ?? null);
        }

        if ($ratio === null) {
            $ratio = 0.0;
        }

        if ($ratio < 0.0) {
            $ratio = 0.0;
        }

        if ($ratio > 1.0) {
            $ratio = 1.0;
        }

        $percent = (int) round($ratio * 100.0);

        return sprintf('Personenanteil: %d %%', $percent);
    }

    private function formatCompanionNames(array $params): string
    {
        $names = $this->extractCompanionNames($params);
        if ($names === []) {
            return '';
        }

        $displayNames = [];
        foreach ($names as $name) {
            $formatted = $this->formatName($name);
            if ($formatted === '') {
                continue;
            }

            if (!in_array($formatted, $displayNames, true)) {
                $displayNames[] = $formatted;
            }
        }

        if ($displayNames === []) {
            return '';
        }

        $maxNames = 3;
        if (count($displayNames) > $maxNames) {
            $visible  = array_slice($displayNames, 0, $maxNames - 1);
            $remaining = count($displayNames) - ($maxNames - 1);
            $visible[] = sprintf('%d weitere', $remaining);
            $displayNames = $visible;
        }

        if (count($displayNames) === 1) {
            return $displayNames[0];
        }

        if (count($displayNames) === 2) {
            return $displayNames[0] . ' & ' . $displayNames[1];
        }

        $lastIndex = count($displayNames) - 1;
        $last      = $displayNames[$lastIndex];
        $initial   = array_slice($displayNames, 0, $lastIndex);

        return implode(', ', $initial) . ' & ' . $last;
    }

    /**
     * @return list<string>
     */
    private function extractCompanionNames(array $params): array
    {
        $cohortMembers = $this->sanitizeCohortMembers($params['cohort_members'] ?? null);

        $telemetryCounts = null;
        $memberSelection = $params['member_selection'] ?? null;
        if (is_array($memberSelection)) {
            $telemetry = $memberSelection['telemetry'] ?? null;
            if (is_array($telemetry) && array_key_exists('people_balance_counts', $telemetry)) {
                $telemetryCounts = $telemetry['people_balance_counts'];
            }
        }

        $peopleCounts = $this->sanitizePeopleCounts($telemetryCounts);

        if ($cohortMembers !== []) {
            $map = $this->mapSignaturesToNames($peopleCounts);
            arsort($cohortMembers);

            $selected = [];
            foreach ($cohortMembers as $personId => $count) {
                if ($count <= 0) {
                    continue;
                }

                $name = $map[$personId] ?? null;
                if ($name === null) {
                    continue;
                }

                $selected[$name] = $count;
            }

            if ($selected !== []) {
                arsort($selected);

                return array_keys($selected);
            }
        }

        if ($peopleCounts === []) {
            return [];
        }

        arsort($peopleCounts);

        return array_keys($peopleCounts);
    }

    /**
     * @param array<string, int> $peopleCounts
     *
     * @return array<int, string>
     */
    private function mapSignaturesToNames(array $peopleCounts): array
    {
        if ($peopleCounts === []) {
            return [];
        }

        $map = [];
        foreach ($peopleCounts as $name => $count) {
            if ($count <= 0) {
                continue;
            }

            $signature = $this->hashPerson($name);
            if ($signature === null) {
                continue;
            }

            if (!array_key_exists($signature, $map)) {
                $map[$signature] = $name;
            }
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private function sanitizeCohortMembers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $personId => $count) {
            if (!is_int($personId) || $personId <= 0) {
                continue;
            }

            $normalized = $this->intOrNull($count);
            if ($normalized === null || $normalized <= 0) {
                continue;
            }

            $result[$personId] = $normalized;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function sanitizePeopleCounts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $counts = [];
        foreach ($value as $name => $count) {
            if (!is_string($name)) {
                continue;
            }

            $trimmed = trim($name);
            if ($trimmed === '') {
                continue;
            }

            $normalized = $this->intOrNull($count);
            if ($normalized === null || $normalized <= 0) {
                continue;
            }

            $counts[$trimmed] = $normalized;
        }

        return $counts;
    }

    private function formatName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
    }

    private function resolvePrimaryLocationLabel(array $params): string
    {
        $candidates = [
            $params['place_city'] ?? null,
            $params['place'] ?? null,
            $params['primaryStaypointCity'] ?? null,
            $params['place_region'] ?? null,
            $params['primaryStaypointRegion'] ?? null,
            $params['place_country'] ?? null,
            $params['primaryStaypointCountry'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function joinParts(array $parts, string $separator = ' • '): string
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

        if ($filtered === []) {
            return '';
        }

        return implode($separator, $filtered);
    }

    private function resolveLocale(?string $locale): string
    {
        if (is_string($locale)) {
            $trimmed = trim($locale);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $this->preferredLocale;
    }

    private function stringOrEmpty(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function hashPerson(string $name): ?int
    {
        $normalized = trim(mb_strtolower($name, 'UTF-8'));
        if ($normalized === '') {
            return null;
        }

        $hash = substr(hash('sha256', $normalized), 0, 15);
        $value = intval($hash, 16);
        if ($value < 1) {
            return 1;
        }

        if ($value > PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return $value;
    }
}
