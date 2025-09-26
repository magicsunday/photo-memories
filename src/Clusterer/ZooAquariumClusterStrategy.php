<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Clusters "Zoo & Aquarium" moments using filename/path keywords and compact time sessions.
 */
final class ZooAquariumClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 2 * 3600,
        private readonly float $radiusMeters = 400.0,
        private readonly int $minItemsPerRun = 5,
        private readonly int $minHour = 9,
        private readonly int $maxHour = 20
    ) {
        if ($this->sessionGapSeconds < 1) {
            throw new \InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }
        if ($this->radiusMeters <= 0.0) {
            throw new \InvalidArgumentException('radiusMeters must be > 0.');
        }
        if ($this->minItemsPerRun < 1) {
            throw new \InvalidArgumentException('minItemsPerRun must be >= 1.');
        }
        if ($this->minHour < 0 || $this->minHour > 23 || $this->maxHour < 0 || $this->maxHour > 23) {
            throw new \InvalidArgumentException('Hour bounds must be within 0..23.');
        }
        if ($this->minHour > $this->maxHour) {
            throw new \InvalidArgumentException('minHour must be <= maxHour.');
        }
    }

    public function name(): string
    {
        return 'zoo_aquarium';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $cand */
        $cand = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m) use ($tz): bool {
                $t = $m->getTakenAt();
                \assert($t instanceof DateTimeImmutable);

                $h = (int) $t->setTimezone($tz)->format('G');
                if ($h < $this->minHour || $h > $this->maxHour) {
                    return false;
                }

                $path = \strtolower($m->getPath());

                return $this->looksZoo($path);
            }
        );

        if (\count($cand) < $this->minItemsPerRun) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf = [];
        $last = null;

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }
            if ($last !== null && ($ts - $last) > $this->sessionGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf = [];
            }
            $buf[] = $m;
            $last = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $gps = $this->filterGpsItems($run);
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];

            // spatial compactness if GPS exists
            $ok = true;
            foreach ($gps as $m) {
                $d = MediaMath::haversineDistanceInMeters(
                    (float) $centroid['lat'],
                    (float) $centroid['lon'],
                    (float) $m->getGpsLat(),
                    (float) $m->getGpsLon()
                );
                if ($d > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }
            if ($ok === false) {
                continue;
            }

            $time = MediaMath::timeRange($run);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $run)
            );
        }

        return $out;
    }

    private function looksZoo(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['zoo', 'tierpark', 'wildpark', 'safari park', 'aquarium', 'sealife', 'sea life', 'zoopark'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
