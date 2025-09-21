<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Small helper to build ClusterDraft fields from Media lists.
 */
trait ClusterBuildHelperTrait
{
    /**
     * @param list<Media> $members
     * @return array{lat: float, lon: float}
     */
    private function computeCentroid(array $members): array
    {
        return MediaMath::centroid($members);
    }

    /**
     * @param list<Media> $members
     * @return list<int>
     */
    private function toMemberIds(array $members): array
    {
        $out = [];
        foreach ($members as $m) {
            $out[] = $m->getId();
        }
        return $out;
    }

    /**
     * @param list<Media> $members
     * @return array{from:int,to:int}
     */
    private function computeTimeRange(array $members): array
    {
        $from = \PHP_INT_MAX;
        $to   = 0;

        foreach ($members as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts !== null) {
                if ($ts < $from) { $from = $ts; }
                if ($ts > $to)   { $to   = $ts; }
            }
        }

        if ($from === \PHP_INT_MAX) {
            $from = 0;
        }
        return ['from' => $from, 'to' => $to];
    }
}
