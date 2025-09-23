<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Shared logic for at-home day/weekend clustering strategies.
 */
abstract class AbstractAtHomeClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly ?float $homeLat,
        private readonly ?float $homeLon,
        private readonly float $homeRadiusMeters,
        private readonly float $minHomeShare,
        private readonly int $minItemsPerDay,
        private readonly int $minItemsTotal,
        string $timezone
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    final public function cluster(array $items): array
    {
        if ($this->homeLat === null || $this->homeLon === null) {
            return [];
        }

        $byDay = $this->groupByDay($items);
        if ($byDay === []) {
            return [];
        }

        $homeOnly = $this->filterHomeDays($byDay);
        if ($homeOnly === []) {
            return [];
        }

        $runs = $this->buildConsecutiveRuns($homeOnly);

        $drafts = [];
        foreach ($runs as $run) {
            if (\count($run['items']) < $this->minItemsTotal) {
                continue;
            }

            $drafts[] = $this->buildClusterDraft(
                $this->name(),
                $run['items'],
                $this->additionalDraftParams($run)
            );
        }

        return $drafts;
    }

    /**
     * @param list<Media> $items
     * @return array<string, list<Media>>
     */
    private function groupByDay(array $items): array
    {
        $byDay = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $takenAt->setTimezone($this->timezone);
            $dow   = (int) $local->format('N');
            if (!$this->isDesiredDay($dow)) {
                continue;
            }

            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $media;
        }

        return $byDay;
    }

    /**
     * @param array<string, list<Media>> $byDay
     * @return array<string, list<Media>>
     */
    private function filterHomeDays(array $byDay): array
    {
        $homeOnly = [];

        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minItemsPerDay) {
                continue;
            }

            $within = [];
            foreach ($list as $media) {
                $lat = $media->getGpsLat();
                $lon = $media->getGpsLon();
                if ($lat === null || $lon === null) {
                    continue;
                }

                $dist = MediaMath::haversineDistanceInMeters(
                    (float) $lat,
                    (float) $lon,
                    (float) $this->homeLat,
                    (float) $this->homeLon
                );

                if ($dist <= $this->homeRadiusMeters) {
                    $within[] = $media;
                }
            }

            if ($within === []) {
                continue;
            }

            $share = \count($within) / (float) \count($list);
            if ($share >= $this->minHomeShare) {
                $homeOnly[$day] = $within;
            }
        }

        return $homeOnly;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @return array<string,mixed>
     */
    protected function additionalDraftParams(array $run): array
    {
        return [];
    }

    /**
     * @param int $dayOfWeek 1=Mon..7=Sun
     */
    abstract protected function isDesiredDay(int $dayOfWeek): bool;
}
