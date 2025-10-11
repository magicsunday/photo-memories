<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function trim;

use const SORT_STRING;

/**
 * Adds canonical titles/subtitles for consolidated vacation clusters.
 */
final class CanonicalTitleStage implements ClusterConsolidationStageInterface
{
    public function getLabel(): string
    {
        return 'Kanontitel';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);
        if ($progress !== null) {
            $progress(0, $total);
        }

        foreach ($drafts as $index => $draft) {
            if ($draft->getAlgorithm() !== 'vacation') {
                continue;
            }

            $params = $draft->getParams();
            $title = $this->buildTitle($params);
            if ($title !== '') {
                $draft->setParam('canonical_title', $title);
            }

            $subtitle = $this->buildSubtitle($params);
            if ($subtitle !== '') {
                $draft->setParam('canonical_subtitle', $subtitle);
            }

            if ($progress !== null && ($index % 200) === 0) {
                $progress($index + 1, $total);
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        return $drafts;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildTitle(array $params): string
    {
        $routeParts = $this->resolveRouteParts($params);
        if ($routeParts !== []) {
            return 'Route: ' . implode(' – ', $routeParts);
        }

        $countries = $this->resolveCountries($params);
        if ($countries !== []) {
            return 'Route: ' . implode(' → ', $countries);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildSubtitle(array $params): string
    {
        $parts = [];

        $label = $params['classification_label'] ?? null;
        if (is_string($label) && $label !== '') {
            $parts[] = $label;
        }

        $duration = $this->resolveDurationLabel($params);
        if ($duration !== '') {
            $parts[] = $duration;
        }

        $range = $this->formatDateRange($params['time_range'] ?? null);
        if ($range !== '') {
            $parts[] = $range;
        }

        return $parts !== [] ? implode(' • ', $parts) : '';
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function resolveRouteParts(array $params): array
    {
        $parts = [];

        $staypointParts = $params['primaryStaypointLocationParts'] ?? null;
        if (is_array($staypointParts)) {
            foreach ($staypointParts as $part) {
                if (is_string($part)) {
                    $trimmed = trim($part);
                    if ($trimmed !== '') {
                        $parts[] = $trimmed;
                    }
                }
            }
        }

        if ($parts === []) {
            $location = $params['place_location'] ?? null;
            if (is_string($location) && $location !== '') {
                $candidates = array_map(
                    static fn (string $segment): string => trim($segment),
                    explode(',', $location),
                );
                $candidates = array_filter(
                    $candidates,
                    static fn (string $segment): bool => $segment !== '',
                );
                if ($candidates !== []) {
                    $parts = $candidates;
                }
            }
        }

        if ($parts === []) {
            $place = $params['place'] ?? null;
            if (is_string($place)) {
                $trimmed = trim($place);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
            }
        }

        if ($parts === []) {
            return [];
        }

        $unique = array_values(array_unique($parts, SORT_STRING));

        return $unique;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function resolveCountries(array $params): array
    {
        $countries = $params['countries'] ?? null;
        if (!is_array($countries) || $countries === []) {
            return [];
        }

        $resolved = [];
        foreach ($countries as $country) {
            if (is_scalar($country)) {
                $trimmed = trim((string) $country);
                if ($trimmed !== '') {
                    $resolved[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($resolved, SORT_STRING));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveDurationLabel(array $params): string
    {
        $days = $params['away_days'] ?? ($params['total_days'] ?? null);
        if (!is_numeric($days)) {
            return '';
        }

        $value = (int) $days;
        if ($value <= 0) {
            return '';
        }

        return $value === 1 ? '1 Tag' : $value . ' Tage';
    }

    private function formatDateRange(mixed $range): string
    {
        if (!is_array($range) || !isset($range['from'], $range['to'])) {
            return '';
        }

        $from = $range['from'];
        $to   = $range['to'];
        if (!is_numeric($from) || !is_numeric($to)) {
            return '';
        }

        $fromTs = (int) $from;
        $toTs   = (int) $to;
        if ($fromTs <= 0 || $toTs <= 0) {
            return '';
        }

        $timezone = new DateTimeZone('Europe/Berlin');
        $fromDate = (new DateTimeImmutable('@' . $fromTs))->setTimezone($timezone);
        $toDate   = (new DateTimeImmutable('@' . $toTs))->setTimezone($timezone);

        if ($fromDate->format('Y-m-d') === $toDate->format('Y-m-d')) {
            return $fromDate->format('d.m.Y');
        }

        if ($fromDate->format('Y') === $toDate->format('Y')) {
            return $fromDate->format('d.m.') . ' – ' . $toDate->format('d.m.Y');
        }

        return $fromDate->format('d.m.Y') . ' – ' . $toDate->format('d.m.Y');
    }
}
