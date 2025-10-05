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
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function assert;
use function array_key_first;
use function count;
use function is_string;
use function trim;

final readonly class DeviceSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locHelper,
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
            $device = $m->getCameraModel() ?? 'unbekannt';
            $locKey = $this->locHelper->localityKeyForMedia($m) ?? 'noloc';

            $key = $device . '|' . $date . '|' . $locKey;
            $groups[$key] ??= [];
            $groups[$key][] = $m;
            $devices[$key]  = $device;
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
            $label  = $this->locHelper->majorityLabel($group);
            $params = [
                'time_range' => $this->computeTimeRange($group),
                'device'     => $devices[$key] ?? 'Unbekannt',
            ];
            if ($label !== null) {
                $params['place'] = $label;
            }

            $metadata = $this->summarizeStableMetadata($group);
            if ($metadata['lensModel'] !== null) {
                $params['lensModel'] = $metadata['lensModel'];
            }

            if ($metadata['contentKind'] !== null) {
                $params['contentKind'] = $metadata['contentKind'];
            }

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

        $stableLens   = count($lensModels) === 1 ? (string) array_key_first($lensModels) : null;
        $stableKind   = count($contentKinds) === 1 ? (string) array_key_first($contentKinds) : null;

        return [
            'lensModel'   => $stableLens,
            'contentKind' => $stableKind,
        ];
    }
}
