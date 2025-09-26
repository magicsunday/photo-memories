<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds "Snow Day" clusters using winter months and snow/ski keywords.
 */
final class SnowDayClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 2 * 3600,
        private readonly int $minItemsPerRun = 6
    ) {
        if ($this->sessionGapSeconds < 1) {
            throw new \InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }
        if ($this->minItemsPerRun < 1) {
            throw new \InvalidArgumentException('minItemsPerRun must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'snow_day';
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

                $local = $t->setTimezone($tz);
                $month = (int) $local->format('n');
                if ($month !== 12 && $month > 2) {
                    return false;
                }

                $path = \strtolower($m->getPath());

                return $this->looksSnow($path);
            }
        );

        if (\count($cand) < $this->minItemsPerRun) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<Media> $buf */
        $buf = [];
        $lastTs = null;

        $flush = function () use (&$buf, &$out): void {
            if (\count($buf) < $this->minItemsPerRun) {
                $buf = [];
                return;
            }
            $centroid = MediaMath::centroid($buf);
            $time     = MediaMath::timeRange($buf);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $buf)
            );
            $buf = [];
        };

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }
            if ($lastTs !== null && ($ts - $lastTs) > $this->sessionGapSeconds) {
                $flush();
            }
            $buf[] = $m;
            $lastTs = $ts;
        }
        $flush();

        return $out;
    }

    private function looksSnow(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['schnee', 'snow', 'ski', 'langlauf', 'skitour', 'snowboard', 'piste', 'eiszapfen'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
