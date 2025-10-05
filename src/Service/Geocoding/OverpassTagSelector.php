<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\Contract\OverpassTagSelectorInterface;

use function array_any;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function is_string;
use function ksort;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const SORT_STRING;

/**
 * Class OverpassTagSelector
 */
final readonly class OverpassTagSelector implements OverpassTagSelectorInterface
{
    /**
     * Additional tags that are preserved even if they are not a category key.
     * Represented as a list of tag names.
     */
    private const array AUXILIARY_TAG_KEYS = [
        'wikidata',
    ];

    public function __construct(private readonly OverpassTagConfiguration $configuration)
    {
    }

    public function select(array $tags): array
    {
        $selected = $this->filterAllowedTags($tags);

        foreach (self::AUXILIARY_TAG_KEYS as $key) {
            $value = $this->stringOrNull($tags[$key] ?? null);
            if ($value !== null) {
                $selected[$key] = $value;
            }
        }

        $names = $this->extractNames($tags);

        return [
            'tags'  => $selected,
            'names' => $names,
        ];
    }

    /**
     * @return array{
     *     default: ?string,
     *     localized: array<string, string>,
     *     alternates: list<string>
     * }
     */
    private function extractNames(array $tags): array
    {
        $default = $this->stringOrNull($tags['name'] ?? null);

        /** @var array<string,string> $localized */
        $localized = [];
        foreach ($tags as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!str_starts_with($key, 'name:')) {
                continue;
            }

            $locale = substr($key, 5);
            if ($locale === false) {
                continue;
            }

            $locale = strtolower($locale);
            if ($locale === '') {
                continue;
            }

            $normalizedLocale = str_replace(' ', '_', $locale);
            $name             = $this->stringOrNull($value);
            if ($name === null) {
                continue;
            }

            $localized[$normalizedLocale] = $name;
        }

        if ($localized !== []) {
            ksort($localized, SORT_STRING);
        }

        $alternates = [];
        $altName    = $this->stringOrNull($tags['alt_name'] ?? null);
        if ($altName !== null) {
            $parts = array_map(static fn (string $part): string => trim($part), explode(';', $altName));
            $parts = array_filter($parts, static fn (string $part): bool => $part !== '');
            if ($parts !== []) {
                /** @var list<string> $unique */
                $unique     = array_values(array_unique($parts));
                $alternates = $unique;
            }
        }

        return [
            'default'    => $default,
            'localized'  => $localized,
            'alternates' => $alternates,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function filterAllowedTags(array $tags): array
    {
        $allowed = [];
        foreach ($this->configuration->getAllowedTagMap() as $key => $values) {
            $value = $this->stringOrNull($tags[$key] ?? null);
            if ($value === null) {
                continue;
            }

            if ($this->isAllowedTagValue($value, $values)) {
                $allowed[$key] = $value;
            }
        }

        return $allowed;
    }

    /**
     * @param list<string> $allowedValues
     */
    private function isAllowedTagValue(string $value, array $allowedValues): bool
    {
        if ($allowedValues === []) {
            return false;
        }

        return array_any(
            $allowedValues,
            static fn (string $allowed): bool => $allowed === $value
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
