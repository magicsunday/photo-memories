<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Utility\Contract\PoiLabelResolverInterface;

use function array_find;
use function array_key_exists;
use function array_keys;
use function explode;
use function is_array;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Resolves the preferred label for a POI based on locale preferences.
 */
final class DefaultPoiLabelResolver implements PoiLabelResolverInterface
{
    /**
     * @var list<string>
     */
    private array $preferredLocaleKeys;

    public function __construct(?string $preferredLocale = null)
    {
        $preferredLocale = $preferredLocale !== null ? trim($preferredLocale) : null;
        if ($preferredLocale === '') {
            $preferredLocale = null;
        }

        $this->preferredLocaleKeys = $this->buildPreferredLocaleKeys($preferredLocale);
    }

    public function preferredLabel(array $poi): ?string
    {
        $label = $this->labelFromNames($poi['names']);
        if ($label !== null) {
            return $label;
        }

        $name = $poi['name'];
        if ($name !== null && $name !== '') {
            return $name;
        }

        return null;
    }

    /**
     * @param array{default:string|null,localized:array<string,string>,alternates:list<string>} $names
     */
    private function labelFromNames(array $names): ?string
    {
        $localized = $names['localized'];
        if ($localized !== []) {
            $preferredLocaleKey = array_find(
                $this->preferredLocaleKeys,
                fn (string $key): bool => $key !== ''
                    && array_key_exists($key, $localized)
                    && $localized[$key] !== ''
            );

            if ($preferredLocaleKey !== null) {
                return $localized[$preferredLocaleKey];
            }
        }

        $default = $names['default'];
        if ($default !== null && $default !== '') {
            return $default;
        }

        $firstLocalized = array_find(
            $localized,
            static fn (string $value): bool => $value !== ''
        );

        if ($firstLocalized !== null) {
            return $firstLocalized;
        }

        $firstAlternate = array_find($names['alternates'], static fn (string $alternate): bool => $alternate !== '');

        if ($firstAlternate !== null) {
            return $firstAlternate;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildPreferredLocaleKeys(?string $locale): array
    {
        if ($locale === null) {
            return [];
        }

        $lower      = strtolower($locale);
        $normalized = str_replace('_', '-', $lower);

        $candidates = [];
        if ($normalized !== '') {
            $candidates[] = $normalized;
            $candidates[] = str_replace('-', '_', $normalized);
        }

        if (str_contains($normalized, '-')) {
            $language = explode('-', $normalized)[0];
            if ($language !== '') {
                $candidates[] = $language;
            }
        } elseif ($normalized !== '') {
            $candidates[] = $normalized;
        }

        $filtered = [];
        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            $filtered[$trimmed] = true;
        }

        /** @var list<string> $keys */
        $keys = array_keys($filtered);

        return $keys;
    }
}
