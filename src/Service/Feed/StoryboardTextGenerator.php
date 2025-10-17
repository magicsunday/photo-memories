<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use Stringable;

use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_slice;
use function arsort;
use function explode;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;
use function substr;
use function trim;

/**
 * Generates localized storyboard titles and descriptions based on feed entries.
 */
final readonly class StoryboardTextGenerator
{
    private string $defaultLocale;

    /**
     * @var list<string>
     */
    private array $supportedLocales;

    /**
     * @param array<int, string|Stringable> $supportedLocales
     */
    public function __construct(string $defaultLocale = 'de', array $supportedLocales = ['de', 'en'])
    {
        $normalizedDefault = $this->normalizeLocaleCode($defaultLocale);
        if ($normalizedDefault === '') {
            $normalizedDefault = 'de';
        }

        $locales = [];
        foreach ($supportedLocales as $localeCandidate) {
            $localeString = $this->toLocaleString($localeCandidate);
            if ($localeString === null) {
                continue;
            }

            $candidate = $this->normalizeLocaleCode($localeString);
            if ($candidate === '') {
                continue;
            }

            if (!in_array($candidate, $locales, true)) {
                $locales[] = $candidate;
            }
        }

        if (!in_array($normalizedDefault, $locales, true)) {
            $locales[] = $normalizedDefault;
        }

        if ($locales === []) {
            $locales[] = $normalizedDefault;
        }

        $this->defaultLocale    = $normalizedDefault;
        $this->supportedLocales = $locales;
    }

    private function toLocaleString(string|Stringable $localeCandidate): ?string
    {
        $value = (string) $localeCandidate;

        return $value === '' ? null : $value;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, int|float|string|array<array-key, scalar|null>|null> $clusterParams
     *
     * @return array{title: string, description: string}
     */
    public function generate(array $entries, array $clusterParams = [], string $locale = 'de'): array
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $phrases        = $this->phrases($resolvedLocale);

        $location = $this->resolveLocation($entries, $clusterParams);
        $persons  = $this->resolveTopPersons($entries);
        $scenes   = $this->resolveTopTags($entries, 'szenen');
        $keywords = $this->resolveTopTags($entries, 'schlagwoerter');

        $title = $phrases['title_generic'];
        if ($location !== null && $persons !== []) {
            $title = sprintf($phrases['title_location_persons'], $this->formatList($persons, $resolvedLocale), $location);
        } elseif ($location !== null) {
            $title = sprintf($phrases['title_location'], $location);
        } elseif ($persons !== []) {
            $title = sprintf($phrases['title_persons'], $this->formatList($persons, $resolvedLocale));
        }

        $sentences = [];
        if ($location !== null && $persons !== []) {
            $sentences[] = sprintf($phrases['description_location_persons'], $this->formatList($persons, $resolvedLocale), $location);
        } elseif ($location !== null) {
            $sentences[] = sprintf($phrases['description_location'], $location);
        } elseif ($persons !== []) {
            $sentences[] = sprintf($phrases['description_persons'], $this->formatList($persons, $resolvedLocale));
        }

        if ($scenes !== []) {
            $sentences[] = sprintf($phrases['description_scenes'], $this->formatList($scenes, $resolvedLocale));
        }

        if ($keywords !== []) {
            $sentences[] = sprintf($phrases['description_keywords'], $this->formatList($keywords, $resolvedLocale));
        }

        if ($sentences === []) {
            $sentences[] = $phrases['description_generic'];
        }

        $description = $this->combineSentences($sentences);

        return [
            'title'       => $title,
            'description' => $description,
        ];
    }

    public function normaliseLocale(string $locale): string
    {
        return $this->resolveLocale($locale);
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    private function resolveLocale(string $locale): string
    {
        $candidate = $this->normalizeLocaleCode($locale);
        if ($candidate === '') {
            return $this->defaultLocale;
        }

        if (!in_array($candidate, $this->supportedLocales, true)) {
            return $this->defaultLocale;
        }

        return $candidate;
    }

    private function normalizeLocaleCode(string $locale): string
    {
        $trimmed = trim($locale);
        if ($trimmed === '') {
            return '';
        }

        $normalized = substr(strtolower($trimmed), 0, 2);

        if ($normalized === false || $normalized === '') {
            return '';
        }

        return $normalized;
    }

    /**
     * @param list<string> $sentences
     */
    private function combineSentences(array $sentences): string
    {
        $normalised = [];
        foreach ($sentences as $sentence) {
            $trimmed = trim($sentence);
            if ($trimmed === '') {
                continue;
            }

            $normalised[] = rtrim($trimmed, '.') . '.';
        }

        if ($normalised === []) {
            return '';
        }

        return implode(' ', $normalised);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, int|float|string|array<array-key, scalar|null>|null> $clusterParams
     */
    private function resolveLocation(array $entries, array $clusterParams): ?string
    {
        /** @var array<string, int> $scores */
        $scores = [];

        $this->registerLocationCandidate($scores, $clusterParams['poi_label'] ?? null, 6);
        $this->registerLocationCandidate($scores, $clusterParams['place'] ?? null, 5);
        $this->registerLocationCandidate($scores, $clusterParams['place_location'] ?? null, 4);
        $this->registerLocationCandidate($scores, $clusterParams['place_city'] ?? null, 3);
        $this->registerLocationCandidate($scores, $clusterParams['place_region'] ?? null, 2);
        $this->registerLocationCandidate($scores, $clusterParams['place_country'] ?? null, 1);

        foreach ($entries as $entry) {
            if (!array_key_exists('ort', $entry)) {
                continue;
            }

            $this->registerLocationCandidate($scores, $entry['ort'], 1);
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $firstKey = array_key_first($scores);
        if (!is_string($firstKey)) {
            $keys = array_keys($scores);

            return is_string($keys[0] ?? null) ? $keys[0] : null;
        }

        return $firstKey;
    }

    /**
     * @param array<string, int> $scores
     * @param array<array-key, scalar|null>|scalar|null $value
     */
    private function registerLocationCandidate(array &$scores, array|int|float|string|null $value, int $weight): void
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                $this->registerLocationCandidate($scores, $entry, $weight);
            }

            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        $label = trim((string) $value);
        if ($label === '') {
            return;
        }

        if (!array_key_exists($label, $scores)) {
            $scores[$label] = 0;
        }

        $scores[$label] += $weight;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<string>
     */
    private function resolveTopPersons(array $entries): array
    {
        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($entries as $entry) {
            $persons = $entry['personen'] ?? null;
            if (!is_array($persons)) {
                continue;
            }

            foreach ($persons as $person) {
                if (!is_string($person)) {
                    continue;
                }

                $label = trim($person);
                if ($label === '') {
                    continue;
                }

                if (!array_key_exists($label, $counts)) {
                    $counts[$label] = 0;
                }

                ++$counts[$label];
            }
        }

        if ($counts === []) {
            return [];
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, 3);
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<string>
     */
    private function resolveTopTags(array $entries, string $key): array
    {
        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($entries as $entry) {
            $tags = $entry[$key] ?? null;
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (!is_string($tag)) {
                    continue;
                }

                $label = trim($tag);
                if ($label === '') {
                    continue;
                }

                if (!array_key_exists($label, $counts)) {
                    $counts[$label] = 0;
                }

                ++$counts[$label];
            }
        }

        if ($counts === []) {
            return [];
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, 3);
    }

    /**
     * @param list<int|float|string|Stringable|null> $items
     */
    private function formatList(array $items, string $locale): string
    {
        $filtered = [];
        foreach ($items as $item) {
            $value = $this->normalizeScalarString($item);
            if ($value === null) {
                continue;
            }

            if (!in_array($value, $filtered, true)) {
                $filtered[] = $value;
            }
        }

        $count = count($filtered);
        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $filtered[0];
        }

        $conjunction = $locale === 'en' ? 'and' : 'und';
        if ($count === 2) {
            return $filtered[0] . ' ' . $conjunction . ' ' . $filtered[1];
        }

        $last   = $filtered[$count - 1];
        $prefix = implode(', ', array_slice($filtered, 0, $count - 1));

        return $prefix . ' ' . $conjunction . ' ' . $last;
    }

    /**
     * @return array<string, string>
     */
    private function phrases(string $locale): array
    {
        $catalogue = [
            'de' => [
                'title_location_persons'      => 'Mit %s in %s',
                'title_location'              => 'Momente in %s',
                'title_persons'               => 'Mit %s',
                'title_generic'               => 'Besondere Erinnerungen',
                'description_location_persons' => 'Gemeinsam mit %s in %s',
                'description_location'        => 'Aufgenommen in %s',
                'description_persons'         => 'Gemeinsam mit %s',
                'description_scenes'          => 'Szenen: %s',
                'description_keywords'        => 'Tags: %s',
                'description_generic'         => 'Unvergessliche Augenblicke.',
            ],
            'en' => [
                'title_location_persons'      => 'With %s in %s',
                'title_location'              => 'Moments in %s',
                'title_persons'               => 'With %s',
                'title_generic'               => 'Special memories',
                'description_location_persons' => 'Together with %s in %s',
                'description_location'        => 'Captured in %s',
                'description_persons'         => 'Together with %s',
                'description_scenes'          => 'Scenes: %s',
                'description_keywords'        => 'Tags: %s',
                'description_generic'         => 'Unforgettable moments.',
            ],
        ];

        if (!array_key_exists($locale, $catalogue)) {
            return $catalogue['de'];
        }

        return $catalogue[$locale];
    }

    private function normalizeScalarString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
        } elseif ($value instanceof Stringable) {
            $trimmed = trim((string) $value);
        } elseif (is_int($value) || is_float($value)) {
            $trimmed = trim((string) $value);
        } else {
            return null;
        }

        return $trimmed === '' ? null : $trimmed;
    }
}
