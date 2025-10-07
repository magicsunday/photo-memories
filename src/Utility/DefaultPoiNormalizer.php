<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Utility\Contract\PoiNormalizerInterface;

use function array_find;
use function array_keys;
use function is_array;
use function is_string;
use function ksort;
use function str_replace;
use function strtolower;
use function trim;

use const SORT_STRING;

/**
 * Normalises raw POI payloads.
 */
final class DefaultPoiNormalizer implements PoiNormalizerInterface
{
    public function normalise(array $poi): ?array
    {
        $name  = is_string($poi['name'] ?? null) && $poi['name'] !== '' ? $poi['name'] : null;
        $names = $this->normaliseNames($poi['names'] ?? null, $name);
        if ($name === null) {
            $name = $this->coalesceName($names);
        }

        $categoryKey   = is_string($poi['categoryKey'] ?? null) && $poi['categoryKey'] !== '' ? $poi['categoryKey'] : null;
        $categoryValue = is_string($poi['categoryValue'] ?? null) && $poi['categoryValue'] !== '' ? $poi['categoryValue'] : null;

        if ($name === null && $categoryValue === null) {
            return null;
        }

        $tags    = [];
        $rawTags = $poi['tags'] ?? null;
        if (is_array($rawTags)) {
            foreach ($rawTags as $tagKey => $tagValue) {
                if (!is_string($tagKey) || $tagKey === '' || !is_string($tagValue) || $tagValue === '') {
                    continue;
                }

                $tags[$tagKey] = $tagValue;
            }
        }

        return [
            'name'          => $name,
            'names'         => $names,
            'categoryKey'   => $categoryKey,
            'categoryValue' => $categoryValue,
            'tags'          => $tags,
        ];
    }

    /**
     * @param array{default:string|null,localized?:array<string,string>|null,alternates?:list<string>|null}|null $raw
     *
     * @return array{default:string|null,localized:array<string,string>,alternates:list<string>}
     */
    private function normaliseNames(?array $raw, ?string $fallbackDefault): array
    {
        $default    = $fallbackDefault;
        $localized  = [];
        $alternates = [];

        if (is_array($raw)) {
            $rawDefault = $raw['default'] ?? null;
            if (is_string($rawDefault) && $rawDefault !== '') {
                $default = $rawDefault;
            }

            $rawLocalized = $raw['localized'] ?? [];
            if (!is_array($rawLocalized)) {
                $rawLocalized = [];
            }

            foreach ($rawLocalized as $locale => $value) {
                if (!is_string($locale)) {
                    continue;
                }

                $locale = strtolower(str_replace(' ', '_', $locale));
                if ($locale === '') {
                    continue;
                }

                if (!is_string($value) || $value === '') {
                    continue;
                }

                $localized[$locale] = $value;
            }

            $rawAlternates = $raw['alternates'] ?? [];
            if (!is_array($rawAlternates)) {
                $rawAlternates = [];
            }

            foreach ($rawAlternates as $alternate) {
                if (!is_string($alternate)) {
                    continue;
                }

                $trimmed = trim($alternate);
                if ($trimmed === '') {
                    continue;
                }

                $alternates[$trimmed] = true;
            }
        }

        if ($localized !== []) {
            ksort($localized, SORT_STRING);
        }

        /** @var list<string> $alternateList */
        $alternateList = array_keys($alternates);

        return [
            'default'    => $default,
            'localized'  => $localized,
            'alternates' => $alternateList,
        ];
    }

    /**
     * @param array{default:string|null,localized:array<string,string>,alternates:list<string>} $names
     */
    private function coalesceName(array $names): ?string
    {
        $default = $names['default'];
        if (is_string($default) && $default !== '') {
            return $default;
        }

        $localized = array_find(
            $names['localized'],
            static fn (string $value): bool => $value !== ''
        );
        if (is_string($localized)) {
            return $localized;
        }

        $alternate = array_find(
            $names['alternates'],
            static fn (string $value): bool => $value !== ''
        );
        if (is_string($alternate)) {
            return $alternate;
        }

        return null;
    }
}
