<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Multi-day camping runs (consecutive days) using keywords.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 83])]
final class CampingTripClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 3,
        private readonly int $minNights = 2,
        private readonly int $maxNights = 14,
        private readonly int $minItemsTotal = 20
    ) {
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
        }
    }

    public function name(): string
    {
        return 'camping_trip';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            $path = \strtolower($m->getPath());
            if (!$t instanceof DateTimeImmutable || !$this->looksCamping($path)) {
                continue;
            }
            $d = $t->setTimezone($tz)->format('Y-m-d');
            $byDay[$d] ??= [];
            $byDay[$d][] = $m;
        }

        if ($byDay === []) {
            return [];
        }

        $days = \array_keys($byDay);
        \sort($days, \SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<string> $run */
        $run = [];
        $prev = null;

        $flush = function () use (&$run, &$out, $byDay): void {
            if (\count($run) === 0) {
                return;
            }
            $nights = \count($run) - 1;
            if ($nights < $this->minNights || $nights > $this->maxNights) {
                $run = [];
                return;
            }
            /** @var list<Media> $members */
            $members = [];
            foreach ($run as $d) {
                if (\count($byDay[$d]) < $this->minItemsPerDay) {
                    $members = [];
                    break;
                }
                foreach ($byDay[$d] as $m) {
                    $members[] = $m;
                }
            }
            if (\count($members) < $this->minItemsTotal) {
                $run = [];
                return;
            }

            \usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: 'camping_trip',
                params: [
                    'nights'     => $nights,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );

            $run = [];
        };

        foreach ($days as $d) {
            if ($prev !== null && !$this->isNextDay($prev, $d)) {
                $flush();
            }
            $run[] = $d;
            $prev  = $d;
        }
        $flush();

        return $out;
    }

    private function looksCamping(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['camping', 'zelt', 'zelten', 'wohnmobil', 'caravan', 'wohnwagen', 'campground', 'camp site', 'campsite', 'stellplatz'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
