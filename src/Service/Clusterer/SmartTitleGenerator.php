<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Title\TitleTemplateProvider;

use function array_filter;
use function array_map;
use function explode;
use function implode;
use function is_array;
use function is_scalar;
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
    public function __construct(private TitleTemplateProvider $provider)
    {
    }

    public function makeTitle(ClusterDraft $cluster, string $locale = 'de'): string
    {
        $tpl = $this->provider->find($cluster->getAlgorithm(), $locale);
        $raw = $tpl['title'] ?? $this->fallbackTitle($cluster);

        return $this->render($raw, $cluster);
    }

    public function makeSubtitle(ClusterDraft $cluster, string $locale = 'de'): string
    {
        $tpl = $this->provider->find($cluster->getAlgorithm(), $locale);
        $raw = $tpl['subtitle'] ?? $this->fallbackSubtitle($cluster);

        return $this->render($raw, $cluster);
    }

    /** Very small moustache-like renderer for {{ keys }} from params */
    private function render(string $template, ClusterDraft $cluster): string
    {
        $p = $this->normalizeLocationParameters($cluster->getParams());

        // Common computed helpers
        $p['date_range'] ??= $this->formatRange($p['time_range'] ?? null);
        $p['start_date'] ??= $this->formatDate($p['time_range']['from'] ?? null);
        $p['end_date'] ??= $this->formatDate($p['time_range']['to'] ?? null);

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.\|]+)\s*\}\}/', static function (array $m) use ($p): string {
            $candidates = explode('|', $m[1]);
            foreach ($candidates as $candidate) {
                $key = trim($candidate);
                if ($key === '') {
                    continue;
                }

                $val = $p[$key] ?? null;
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

    private function formatRange(mixed $tr): string
    {
        if (is_array($tr) && isset($tr['from'], $tr['to'])) {
            $from = (int) $tr['from'];
            $to   = (int) $tr['to'];
            if ($from > 0 && $to > 0) {
                $df = (new DateTimeImmutable('@' . $from))->setTimezone(new DateTimeZone('Europe/Berlin'));
                $dt = (new DateTimeImmutable('@' . $to))->setTimezone(new DateTimeZone('Europe/Berlin'));
                if ($df->format('Y-m-d') === $dt->format('Y-m-d')) {
                    return $df->format('d.m.Y');
                }

                if ($df->format('Y') === $dt->format('Y')) {
                    return $df->format('d.m.') . ' – ' . $dt->format('d.m.Y');
                }

                return $df->format('d.m.Y') . ' – ' . $dt->format('d.m.Y');
            }
        }

        return '';
    }

    private function formatDate(mixed $ts): string
    {
        $t = is_scalar($ts) ? (int) $ts : 0;

        return $t > 0 ? (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y') : '';
    }

    private function fallbackTitle(ClusterDraft $cluster): string
    {
        return $cluster->getParams()['label'] ?? 'Rückblick';
    }

    private function fallbackSubtitle(ClusterDraft $cluster): string
    {
        return $this->formatRange($cluster->getParams()['time_range'] ?? null);
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
}
