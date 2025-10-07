<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function array_key_first;
use function assert;
use function count;
use function implode;
use function is_string;
use function sprintf;
use function stripos;
use function strlen;
use function substr;
use function trim;

/**
 * Class DeviceSimilarityStrategy.
 */
final readonly class DeviceSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locationHelper,
        private int $minItemsPerGroup = 5,
    ) {
        if ($this->minItemsPerGroup < 1) {
            throw new InvalidArgumentException('minItemsPerGroup must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'device_similarity';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $withTimestamp */
        $withTimestamp = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $groups */
        $groups = [];

        /** @var array<string, string> $devices */
        $devices = [];

        $ingest = function (Media $m, string $date) use (&$groups, &$devices): void {
            $descriptor = $this->buildDeviceDescriptor($m);
            $locKey     = $this->locationHelper->localityKeyForMedia($m) ?? 'noloc';

            $key = $descriptor['key'] . '|' . $date . '|' . $locKey;
            $groups[$key] ??= [];
            $groups[$key][] = $m;
            $devices[$key]  = $descriptor['label'];
        };

        foreach ($withTimestamp as $m) {
            $takenAt = $m->getTakenAt();
            assert($takenAt instanceof DateTimeImmutable);
            $ingest($m, $takenAt->format('Y-m-d'));
        }

        foreach ($items as $m) {
            if ($m->getTakenAt() instanceof DateTimeImmutable) {
                continue;
            }

            // Group timestamp-less media separately so they can still surface.
            $ingest($m, 'ohne-datum');
        }

        /** @var array<string, list<Media>> $eligibleGroups */
        $eligibleGroups = $this->filterGroupsByMinItems($groups, $this->minItemsPerGroup);

        $drafts = [];
        foreach ($eligibleGroups as $key => $group) {
            $params = [
                'time_range' => $this->computeTimeRange($group),
                'device'     => $devices[$key] ?? 'Unbekanntes Gerät',
            ];

            $metadata = $this->summarizeStableMetadata($group);
            if ($metadata['lensModel'] !== null) {
                $params['lensModel'] = $metadata['lensModel'];
            }

            if ($metadata['contentKind'] !== null) {
                $params['contentKind'] = $metadata['contentKind'];
            }

            $peopleParams = $this->buildPeopleParams($group);
            $params       = [...$params, ...$peopleParams];

            $tagMetadata = $this->collectDominantTags($group);
            if ($tagMetadata !== []) {
                $params = [...$params, ...$tagMetadata];
            }

            $params = $this->appendLocationMetadata($group, $params);

            $drafts[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: $this->computeCentroid($group),
                members: $this->toMemberIds($group)
            );
        }

        return $drafts;
    }

    /**
     * Builds a normalized device descriptor to group and label media consistently.
     *
     * @return array{key: string, label: string}
     */
    private function buildDeviceDescriptor(Media $media): array
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
            'key'   => $key,
            'label' => $label,
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
     * @param list<Media> $group
     *
     * @return array{lensModel: ?string, contentKind: ?string}
     */
    private function summarizeStableMetadata(array $group): array
    {
        $lensModels   = [];
        $contentKinds = [];

        foreach ($group as $media) {
            $lensModel = $media->getLensModel();
            if (is_string($lensModel)) {
                $normalized = trim($lensModel);
                if ($normalized !== '') {
                    $lensModels[$normalized] = true;
                }
            }

            $contentKind = $media->getContentKind();
            if ($contentKind instanceof ContentKind) {
                $contentKinds[$contentKind->value] = true;
            }
        }

        $stableLens = count($lensModels) === 1 ? (string) array_key_first($lensModels) : null;
        $stableKind = count($contentKinds) === 1 ? (string) array_key_first($contentKinds) : null;

        return [
            'lensModel'   => $stableLens,
            'contentKind' => $stableKind,
        ];
    }
}
