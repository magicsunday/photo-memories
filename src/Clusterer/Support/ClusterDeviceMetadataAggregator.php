<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Media;

use function array_key_exists;
use function array_sum;
use function implode;
use function sprintf;
use function strcasecmp;
use function stripos;
use function strlen;
use function substr;
use function trim;

/**
 * Aggregates device related metadata for cluster strategies.
 */
final readonly class ClusterDeviceMetadataAggregator
{
    /**
     * Builds the normalized device descriptor for a single media item.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     make: ?string,
     *     model: ?string,
     *     owner: ?string,
     *     serial: ?string
     * }
     */
    public function describe(Media $media): array
    {
        $make   = $this->normalizeDeviceValue($media->getCameraMake());
        $model  = $this->normalizeDeviceValue($media->getCameraModel());
        $owner  = $this->normalizeDeviceValue($media->getCameraOwner());
        $serial = $this->normalizeDeviceValue($media->getCameraBodySerial());

        $key = implode('|', [
            $make ?? 'keine-marke',
            $model ?? 'kein-modell',
            $owner ?? 'kein-besitzer',
            $serial ?? 'keine-seriennummer',
        ]);

        $labelParts = [];
        if ($make !== null) {
            $labelParts[] = $make;
        }

        if ($model !== null) {
            if ($make !== null && stripos($model, $make) === 0) {
                $suffix = trim((string) substr($model, strlen($make)));
                if ($suffix !== '') {
                    $labelParts[] = $suffix;
                }
            } else {
                $labelParts[] = $model;
            }
        }

        $label = trim(implode(' ', $labelParts));
        if ($label === '') {
            $label = 'Unbekanntes Gerät';
        }

        $detailParts = [];
        if ($owner !== null) {
            $detailParts[] = sprintf('Besitzer: %s', $owner);
        }

        if ($serial !== null) {
            $detailParts[] = sprintf('Seriennummer: %s', $serial);
        }

        if ($detailParts !== []) {
            $label .= ' – ' . implode(', ', $detailParts);
        }

        return [
            'key'    => $key,
            'label'  => $label,
            'make'   => $make,
            'model'  => $model,
            'owner'  => $owner,
            'serial' => $serial,
        ];
    }

    /**
     * Summarises the dominant device information for a list of media items.
     *
     * @param list<Media> $mediaItems
     *
     * @return array{
     *     device_primary_label: ?string,
     *     device_primary_share: ?float,
     *     device_variants: int,
     *     device_make: ?string,
     *     device_model: ?string,
     *     device_owner: ?string,
     *     device_serial: ?string,
     *     lensModel: ?string,
     *     contentKind: ?string
     * }
     */
    public function summarize(array $mediaItems): array
    {
        if ($mediaItems === []) {
            return [
                'device_primary_label' => null,
                'device_primary_share' => null,
                'device_variants'      => 0,
                'device_make'          => null,
                'device_model'         => null,
                'device_owner'         => null,
                'device_serial'        => null,
                'lensModel'            => null,
                'contentKind'          => null,
            ];
        }

        $descriptorCounts = [];
        $makeCounts       = [];
        $modelCounts      = [];
        $ownerCounts      = [];
        $serialCounts     = [];
        $lensCounts       = [];
        $kindCounts       = [];

        foreach ($mediaItems as $media) {
            $descriptor = $this->describe($media);

            if (!array_key_exists($descriptor['key'], $descriptorCounts)) {
                $descriptorCounts[$descriptor['key']] = [
                    'count' => 0,
                    'label' => $descriptor['label'],
                ];
            }

            $descriptorCounts[$descriptor['key']]['count'] += 1;

            if ($descriptor['make'] !== null) {
                $makeCounts[$descriptor['make']] = ($makeCounts[$descriptor['make']] ?? 0) + 1;
            }

            if ($descriptor['model'] !== null) {
                $modelCounts[$descriptor['model']] = ($modelCounts[$descriptor['model']] ?? 0) + 1;
            }

            if ($descriptor['owner'] !== null) {
                $ownerCounts[$descriptor['owner']] = ($ownerCounts[$descriptor['owner']] ?? 0) + 1;
            }

            if ($descriptor['serial'] !== null) {
                $serialCounts[$descriptor['serial']] = ($serialCounts[$descriptor['serial']] ?? 0) + 1;
            }

            $lensModel = $this->normalizeDeviceValue($media->getLensModel());
            if ($lensModel !== null) {
                $lensCounts[$lensModel] = ($lensCounts[$lensModel] ?? 0) + 1;
            }

            $contentKind = $media->getContentKind();
            if ($contentKind instanceof ContentKind) {
                $kindCounts[$contentKind->value] = ($kindCounts[$contentKind->value] ?? 0) + 1;
            }
        }

        $totalItems = array_sum(array_map(static fn (array $entry): int => $entry['count'], $descriptorCounts));

        $primaryKey    = null;
        $primaryLabel  = null;
        $primaryCount  = 0;
        foreach ($descriptorCounts as $key => $entry) {
            $count = $entry['count'];
            if ($count > $primaryCount) {
                $primaryKey   = $key;
                $primaryLabel = $entry['label'];
                $primaryCount = $count;
                continue;
            }

            if ($count === $primaryCount && $primaryLabel !== null) {
                if (strcasecmp($entry['label'], $primaryLabel) < 0) {
                    $primaryKey   = $key;
                    $primaryLabel = $entry['label'];
                    $primaryCount = $count;
                }
            }
        }

        $primaryShare = null;
        if ($primaryKey !== null && $totalItems > 0) {
            $primaryShare = (float) $primaryCount / (float) $totalItems;
        }

        return [
            'device_primary_label' => $primaryLabel,
            'device_primary_share' => $primaryShare,
            'device_variants'      => (int) count($descriptorCounts),
            'device_make'          => $this->pickStableValue($makeCounts, $totalItems),
            'device_model'         => $this->pickStableValue($modelCounts, $totalItems),
            'device_owner'         => $this->pickStableValue($ownerCounts, $totalItems),
            'device_serial'        => $this->pickStableValue($serialCounts, $totalItems),
            'lensModel'            => $this->pickStableValue($lensCounts, $totalItems),
            'contentKind'          => $this->pickStableValue($kindCounts, $totalItems),
        ];
    }

    private function normalizeDeviceValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param array<string, int> $counts
     */
    private function pickStableValue(array $counts, int $totalItems): ?string
    {
        if ($counts === [] || $totalItems <= 0) {
            return null;
        }

        $dominantValue = null;
        $dominantCount = 0;
        foreach ($counts as $value => $count) {
            if ($count > $dominantCount) {
                $dominantValue = $value;
                $dominantCount = $count;
                continue;
            }

            if ($count === $dominantCount && $dominantValue !== null) {
                if (strcasecmp($value, $dominantValue) < 0) {
                    $dominantValue = $value;
                    $dominantCount = $count;
                }
            }
        }

        if ($dominantValue === null) {
            return null;
        }

        if ($dominantCount === $totalItems) {
            return $dominantValue;
        }

        $share = (float) $dominantCount / (float) $totalItems;

        return $share >= 0.6 ? $dominantValue : null;
    }
}
