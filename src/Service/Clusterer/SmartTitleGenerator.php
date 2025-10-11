<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummary;
use MagicSunday\Memories\Service\Clusterer\Title\TitleTemplateProvider;

use function array_filter;
use function array_map;
use function explode;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function mb_convert_case;
use function mb_strtolower;
use function preg_replace_callback;
use function trim;

use const MB_CASE_TITLE;

/**
 * Renders titles/subtitles using YAML templates + params (iOS-like).
 */
final readonly class SmartTitleGenerator implements TitleGeneratorInterface
{
    public function __construct(
        private TitleTemplateProvider $provider,
        private RouteSummarizer $routeSummarizer,
        private LocalizedDateFormatter $dateFormatter,
    ) {
    }

    public function makeTitle(ClusterDraft $cluster, string $locale = 'de'): string
    {
        $tpl = $this->provider->find($cluster->getAlgorithm(), $locale);
        $raw = $tpl['title'] ?? $this->fallbackTitle($cluster);

        return $this->render($raw, $cluster, $locale);
    }

    public function makeSubtitle(ClusterDraft $cluster, string $locale = 'de'): string
    {
        $tpl = $this->provider->find($cluster->getAlgorithm(), $locale);
        $raw = $tpl['subtitle'] ?? $this->fallbackSubtitle($cluster, $locale);

        return $this->render($raw, $cluster, $locale);
    }

    /** Very small moustache-like renderer for {{ keys }} from params */
    private function render(string $template, ClusterDraft $cluster, string $locale): string
    {
        $params = $this->computeParameters($cluster, $locale);

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.\|]+)\s*\}\}/', static function (array $m) use ($params): string {
            $candidates = explode('|', $m[1]);
            foreach ($candidates as $candidate) {
                $key = trim($candidate);
                if ($key === '') {
                    continue;
                }

                $val = $params[$key] ?? null;
                if (is_scalar($val)) {
                    $stringVal = (string) $val;
                    if ($stringVal !== '') {
                        return $stringVal;
                    }
                }
            }

            return '';
        }, $template) ?? $template;
    }

    private function fallbackTitle(ClusterDraft $cluster): string
    {
        return $cluster->getParams()['label'] ?? 'Rückblick';
    }

    private function fallbackSubtitle(ClusterDraft $cluster, string $locale = 'de'): string
    {
        return $this->dateFormatter->formatRange($cluster->getParams()['time_range'] ?? null, $locale);
    }

    /**
     * @param array<string, scalar|array|null> $params
     *
     * @return array<string, scalar|array|null>
     */
    private function normalizeLocationParameters(array $params): array
    {
        $normalized = $params;

        foreach (['place', 'place_city', 'place_region', 'place_country'] as $key) {
            $value = $normalized[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $normalized[$key] = $this->normalizeLocationComponent((string) $value);
        }

        $location = $normalized['place_location'] ?? null;
        if (is_scalar($location)) {
            $normalized['place_location'] = $this->normalizeLocationList((string) $location);
        }

        return $normalized;
    }

    private function normalizeLocationComponent(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');
        if ($trimmed === $lower) {
            return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
        }

        return $trimmed;
    }

    private function normalizeLocationList(string $value): string
    {
        $parts = array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', $value)
        ), static fn (string $part): bool => $part !== '');

        if ($parts === []) {
            return '';
        }

        $normalized = array_map(
            fn (string $part): string => $this->normalizeLocationComponent($part),
            $parts
        );

        return implode(', ', $normalized);
    }

    /**
     * @return array<string, scalar|array|null>
     */
    private function computeParameters(ClusterDraft $cluster, string $locale): array
    {
        $params = $this->normalizeLocationParameters($cluster->getParams());

        $timeRange = $params['time_range'] ?? null;
        $params['date_range'] ??= $this->dateFormatter->formatRange($timeRange, $locale);
        $params['start_date'] ??= $this->dateFormatter->formatDate($timeRange['from'] ?? null, $locale);
        $params['end_date'] ??= $this->dateFormatter->formatDate($timeRange['to'] ?? null, $locale);

        $locationLabel = $this->resolvePrimaryLocationLabel($params);
        if ($locationLabel !== '') {
            $params['primary_location_label'] = $locationLabel;
        }

        return $this->applyAlgorithmEnhancements($cluster, $params, $locale, $locationLabel);
    }

    /**
     * @param array<string, scalar|array|null> $params
     *
     * @return array<string, scalar|array|null>
     */
    private function applyAlgorithmEnhancements(
        ClusterDraft $cluster,
        array $params,
        string $locale,
        string $locationLabel,
    ): array {
        $algorithm = $cluster->getAlgorithm();

        if ($algorithm === 'vacation') {
            $summary = $this->routeSummarizer->summarize($cluster, $locale);

            if ($summary instanceof RouteSummary) {
                if ($summary->routeLabel !== '') {
                    $params['route_label'] = $summary->routeLabel;
                }

                if ($summary->distanceLabel !== '') {
                    $params['route_distance_label'] = $summary->distanceLabel;
                }

                if ($summary->stopLabel !== '') {
                    $params['route_stop_label'] = $summary->stopLabel;
                }

                if ($summary->legLabel !== '') {
                    $params['route_leg_label'] = $summary->legLabel;
                }

                if ($summary->metricsLabel !== '') {
                    $params['route_metrics_label'] = $summary->metricsLabel;
                }
            } else {
                $summary = null;
            }

            $params['vacation_title'] = $this->buildVacationTitle($params, $summary);
            $params['vacation_subtitle'] = $this->buildVacationSubtitle($params, $summary);
        }

        if ($algorithm === 'significant_place') {
            $params['subtitle_special'] = $this->joinParts(['Lieblingsort', $locationLabel, $params['date_range'] ?? '']);
        }

        if ($algorithm === 'first_visit_place') {
            $params['subtitle_special'] = $this->joinParts(['Erster Besuch', $locationLabel, $params['date_range'] ?? '']);
        }

        if ($algorithm === 'nightlife_event') {
            $params['subtitle_special'] = $this->joinParts(['Nachtleben', $locationLabel, $params['date_range'] ?? '']);
        }

        if ($algorithm === 'golden_hour') {
            $params['subtitle_special'] = $this->joinParts(['Warmes Licht', $params['date_range'] ?? '']);
        }

        return $params;
    }

    private function buildVacationTitle(array $params, ?RouteSummary $summary): string
    {
        if ($summary instanceof RouteSummary && $summary->routeLabel !== '') {
            return $summary->routeLabel;
        }

        $classification = '';
        $rawClassification = $params['classification_label'] ?? null;
        if (is_string($rawClassification)) {
            $classification = trim($rawClassification);
        }

        if ($classification === '') {
            $classification = 'Reise';
        }

        $locationLabel = $this->resolvePrimaryLocationLabel($params);

        return $this->joinParts([$classification, $locationLabel], ' – ');
    }

    private function buildVacationSubtitle(array $params, ?RouteSummary $summary): string
    {
        $parts = [];

        if ($summary instanceof RouteSummary) {
            if ($summary->metricsLabel !== '') {
                $parts[] = $summary->metricsLabel;
            } else {
                if ($summary->distanceLabel !== '') {
                    $parts[] = $summary->distanceLabel;
                }

                if ($summary->stopLabel !== '') {
                    $parts[] = $summary->stopLabel;
                }
            }
        }

        $dateRange = $params['date_range'] ?? '';
        if ($dateRange !== '') {
            $parts[] = $dateRange;
        } else {
            $parts[] = $this->joinParts([
                $params['start_date'] ?? '',
                $params['end_date'] ?? '',
            ], ' – ');
        }

        $subtitle = $this->joinParts($parts);
        if ($subtitle !== '') {
            return $subtitle;
        }

        if ($dateRange !== '') {
            return $dateRange;
        }

        return $this->joinParts([
            $params['start_date'] ?? '',
            $params['end_date'] ?? '',
        ], ' – ');
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

    /**
     * @param list<string> $parts
     */
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

        return $filtered === [] ? '' : implode($separator, $filtered);
    }
}
