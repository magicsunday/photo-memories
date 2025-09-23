<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;

/**
 * Base class for strategies that bucket media into keyed groups and build a single cluster per key.
 */
abstract class AbstractGroupedClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    final public function cluster(array $items): array
    {
        $this->beforeGrouping();

        /** @var array<string, list<Media>> $groups */
        $groups = [];

        foreach ($items as $media) {
            if (!$this->shouldConsider($media)) {
                continue;
            }

            $key = $this->groupKey($media);
            if ($key === null) {
                continue;
            }

            $groups[$key] ??= [];
            $groups[$key][] = $media;
        }

        if ($groups === []) {
            return [];
        }

        $drafts = [];

        foreach ($groups as $key => $members) {
            $params = $this->groupParams($key, $members);
            if ($params === null) {
                continue;
            }

            $drafts[] = $this->buildClusterDraft($this->name(), $members, $params);
        }

        return $drafts;
    }

    protected function beforeGrouping(): void
    {
        // Default no-op hook for subclasses that need to prepare per-run context.
    }

    protected function shouldConsider(Media $media): bool
    {
        return true;
    }

    abstract protected function groupKey(Media $media): ?string;

    /**
     * @param list<Media> $members
     *
     * @return array<string, mixed>|null Null to skip the group.
     */
    abstract protected function groupParams(string $key, array $members): ?array;
}
