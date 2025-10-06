<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\Contract\LocationLabelResolverInterface;
use MagicSunday\Memories\Utility\Contract\PoiContextAnalyzerInterface;
use MagicSunday\Memories\Utility\Contract\PoiLabelResolverInterface;

use function array_key_first;
use function arsort;
use function implode;
use function is_string;
use function mb_convert_case;
use function mb_strtolower;
use function strcmp;
use function trim;

use const MB_CASE_TITLE;
use const SORT_NUMERIC;

/**
 * Default implementation generating location labels and keys.
 */
final readonly class DefaultLocationLabelResolver implements LocationLabelResolverInterface
{
    public function __construct(
        private PoiContextAnalyzerInterface $poiContextAnalyzer,
        private PoiLabelResolverInterface $poiLabelResolver,
    ) {
    }

    public function localityKey(?Location $location): ?string
    {
        if (!$location instanceof Location) {
            return null;
        }

        $parts   = [];
        $suburb  = $location->getSuburb();
        $city    = $location->getCity();
        $county  = $location->getCounty();
        $state   = $location->getState();
        $country = $location->getCountry();
        $cell    = $location->getCell();

        if ($suburb !== null) {
            $parts[] = 'suburb:' . $suburb;
        }

        if ($city !== null) {
            $parts[] = 'city:' . $city;
        }

        if ($county !== null) {
            $parts[] = 'county:' . $county;
        }

        if ($state !== null && $city === null && $county === null) {
            $parts[] = 'state:' . $state;
        }

        if ($country !== null) {
            $parts[] = 'country:' . $country;
        }

        if ($parts === [] && $cell !== null) {
            $parts[] = 'cell:' . $cell;
        }

        return $parts !== [] ? implode('|', $parts) : null;
    }

    public function displayLabel(?Location $location): ?string
    {
        if (!$location instanceof Location) {
            return null;
        }

        $poi = $this->poiContextAnalyzer->resolvePrimaryPoi($location);
        if ($poi !== null) {
            $label = $this->poiLabelResolver->preferredLabel($poi);
            if (is_string($label)) {
                $normalizedLabel = trim($label);
                if ($normalizedLabel !== '') {
                    return $this->normalizeLabelCasing($normalizedLabel);
                }
            }
        }

        $components = [
            $location->getCity(),
            $location->getCounty(),
            $location->getState(),
            $location->getCountry(),
        ];

        foreach ($components as $component) {
            if (!is_string($component)) {
                continue;
            }

            $normalizedComponent = trim($component);
            if ($normalizedComponent !== '') {
                return $this->normalizeLabelCasing($normalizedComponent);
            }
        }

        $label = $this->poiContextAnalyzer->bestLabelForLocation($location);
        if (is_string($label)) {
            $normalizedLabel = trim($label);
            if ($normalizedLabel !== '') {
                return $this->normalizeLabelCasing($normalizedLabel);
            }
        }

        return null;
    }

    public function localityKeyForMedia(Media $media): ?string
    {
        return $this->localityKey($media->getLocation());
    }

    public function labelForMedia(Media $media): ?string
    {
        return $this->displayLabel($media->getLocation());
    }

    public function majorityLabel(array $members): ?string
    {
        $poiContext = $this->poiContextAnalyzer->majorityPoiContext($members);
        if ($poiContext !== null) {
            $label = $poiContext['label'] ?? null;
            if (is_string($label)) {
                $normalizedLabel = trim($label);
                if ($normalizedLabel !== '') {
                    $categoryValue = $poiContext['categoryValue'] ?? null;
                    if (!is_string($categoryValue)) {
                        return $this->normalizeLabelCasing($normalizedLabel);
                    }

                    $normalizedCategory = trim($categoryValue);
                    if ($normalizedCategory === ''
                        || mb_strtolower($normalizedLabel, 'UTF-8') !== mb_strtolower($normalizedCategory, 'UTF-8')
                    ) {
                        return $this->normalizeLabelCasing($normalizedLabel);
                    }
                }
            }
        }

        /** @var array<string,int> $count */
        $count = [];
        foreach ($members as $media) {
            $label = $this->labelForMedia($media);
            if ($label === null) {
                continue;
            }

            $count[$label] = ($count[$label] ?? 0) + 1;
        }

        if ($count === []) {
            return null;
        }

        arsort($count, SORT_NUMERIC);

        return array_key_first($count);
    }

    public function majorityLocationComponents(array $members): array
    {
        $counts = [
            'country' => [],
            'region'  => [],
            'city'    => [],
        ];

        foreach ($members as $media) {
            $location = $media->getLocation();
            if (!$location instanceof Location) {
                continue;
            }

            $this->collectComponent($counts['country'], $location->getCountry());
            $this->collectComponent($counts['region'], $location->getState());
            $this->collectComponent($counts['city'], $location->getCity());
        }

        $result = [];

        foreach ($counts as $component => $tallies) {
            $majority = $this->pickMajorityValue($tallies);
            if ($majority !== null) {
                $result[$component] = $majority;
            }
        }

        return $result;
    }

    public function sameLocality(Media $a, Media $b): bool
    {
        $keyA = $this->localityKeyForMedia($a);
        $keyB = $this->localityKeyForMedia($b);

        return $keyA !== null && $keyB !== null && $keyA === $keyB;
    }

    /**
     * @param array<string,array{count:int,variants:array<string,int>}> $bucket
     */
    private function pickMajorityValue(array $bucket): ?string
    {
        $winnerKey   = null;
        $winnerCount = -1;

        foreach ($bucket as $value => $payload) {
            $count = $payload['count'];
            if ($count > $winnerCount) {
                $winnerCount = $count;
                $winnerKey   = $value;

                continue;
            }

            if ($count === $winnerCount && $winnerKey !== null && strcmp($value, $winnerKey) < 0) {
                $winnerKey = $value;
            }
        }

        if ($winnerKey === null) {
            return null;
        }

        $variants = $bucket[$winnerKey]['variants'] ?? [];
        $variant  = $this->pickRepresentativeVariant($variants);

        if ($variant === null) {
            return null;
        }

        return $this->normalizeLabelCasing($variant);
    }

    /**
     * @param array<string,array{count:int,variants:array<string,int>}> $bucket
     */
    private function collectComponent(array &$bucket, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }

        $normalized = mb_strtolower($trimmed, 'UTF-8');

        if (!isset($bucket[$normalized])) {
            $bucket[$normalized] = [
                'count'    => 0,
                'variants' => [],
            ];
        }

        ++$bucket[$normalized]['count'];
        $bucket[$normalized]['variants'][$trimmed] = ($bucket[$normalized]['variants'][$trimmed] ?? 0) + 1;
    }

    /**
     * @param array<string,int> $variants
     */
    private function pickRepresentativeVariant(array $variants): ?string
    {
        $winnerLabel = null;
        $winnerCount = -1;

        foreach ($variants as $label => $count) {
            if ($count > $winnerCount) {
                $winnerCount = $count;
                $winnerLabel = $label;

                continue;
            }

            if ($count === $winnerCount && $winnerLabel !== null && strcmp($label, $winnerLabel) < 0) {
                $winnerLabel = $label;
            }
        }

        return $winnerLabel;
    }

    /**
     * Normalizes the casing of a location label to ensure user-facing output.
     */
    private function normalizeLabelCasing(string $label): string
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            return $trimmed;
        }

        $lowerCase = mb_strtolower($trimmed, 'UTF-8');
        if ($trimmed === $lowerCase) {
            return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
        }

        return $trimmed;
    }
}
