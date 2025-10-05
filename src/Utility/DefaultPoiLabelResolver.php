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
use function array_keys;
use function explode;
use function is_array;
use function is_string;
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
     * @var list<string> $preferredLocaleKeys
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
        $names = $poi['names'] ?? null;
        if (is_array($names)) {
            $label = $this->labelFromNames($names);
            if ($label !== null) {
                return $label;
            }
        }

        $name = $poi['name'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return null;
    }

    /**
     * @param array{default:?string,localized:array<string,string>,alternates:list<string>} $names
     */
    private function labelFromNames(array $names): ?string
    {
        $localized = $names['localized'] ?? [];
        if (is_array($localized) && $localized !== []) {
            $preferredLocaleValue = null;
            $preferredLocaleKey   = array_find(
                $this->preferredLocaleKeys,
                function (string $key) use ($localized, &$preferredLocaleValue): bool {
                    $value = $localized[$key] ?? null;

                    if (!is_string($value) || $value === '') {
                        return false;
                    }

                    $preferredLocaleValue = $value;

                    return true;
                }
            );

            if ($preferredLocaleKey !== null) {
                return $preferredLocaleValue;
            }
        }

        $default = $names['default'] ?? null;
        if (is_string($default) && $default !== '') {
            return $default;
        }

        if (is_array($localized)) {
            $firstLocalized = array_find(
                $localized,
                static fn ($value): bool => is_string($value) && $value !== ''
            );

            if (is_string($firstLocalized)) {
                return $firstLocalized;
            }
        }

        $alternates = $names['alternates'] ?? [];
        if (is_array($alternates)) {
            $firstAlternate = array_find(
                $alternates,
                static fn ($alternate): bool => is_string($alternate) && $alternate !== ''
            );

            if (is_string($firstAlternate)) {
                return $firstAlternate;
            }
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
            if (!is_string($candidate)) {
                continue;
            }

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
