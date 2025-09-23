<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;

/**
 * Base for strategies that group media by coarse geo cells before building clusters.
 */
abstract class AbstractGeoCellClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(private readonly float $gridDegrees = 0.01)
    {
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    final public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $cells */
        $cells = [];

        foreach ($items as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat === null || $lon === null) {
                continue;
            }

            if (!$this->shouldConsider($media)) {
                continue;
            }

            $key = $this->cellKey((float) $lat, (float) $lon);
            $cells[$key] ??= [];
            $cells[$key][] = $media;
        }

        if ($cells === []) {
            return [];
        }

        $drafts = [];

        foreach ($cells as $cell => $members) {
            if (\count($members) < $this->minMembersPerCell()) {
                continue;
            }

            foreach ($this->clustersForCell($cell, $members) as $draft) {
                $drafts[] = $draft;
            }
        }

        return $drafts;
    }

    protected function shouldConsider(Media $media): bool
    {
        return true;
    }

    protected function minMembersPerCell(): int
    {
        return 1;
    }

    /**
     * @param list<Media> $members
     * @return list<ClusterDraft>
     */
    abstract protected function clustersForCell(string $cell, array $members): array;

    protected function cellKey(float $lat, float $lon): string
    {
        $gd = $this->gridDegrees;
        $rlat = $gd * \floor($lat / $gd);
        $rlon = $gd * \floor($lon / $gd);

        return \sprintf('%.4f,%.4f', $rlat, $rlon);
    }

    protected function gridDegrees(): float
    {
        return $this->gridDegrees;
    }
}
