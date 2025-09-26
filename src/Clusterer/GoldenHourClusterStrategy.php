<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Heuristic "Golden Hour" clusters around morning/evening hours.
 */
final class GoldenHourClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        /** Inclusive local hours considered golden-hour candidates. */
        private readonly array $morningHours = [6, 7, 8],
        private readonly array $eveningHours = [18, 19, 20],
        private readonly int $sessionGapSeconds = 90 * 60,
        private readonly int $minItemsPerRun = 5
    ) {
        if ($this->morningHours === [] || $this->eveningHours === []) {
            throw new \InvalidArgumentException('Morning and evening hours must not be empty.');
        }
        foreach ([$this->morningHours, $this->eveningHours] as $hours) {
            foreach ($hours as $hour) {
                if (!\is_int($hour) || $hour < 0 || $hour > 23) {
                    throw new \InvalidArgumentException('Hour values must be integers within 0..23.');
                }
            }
        }
        if ($this->sessionGapSeconds < 1) {
            throw new \InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }
        if ($this->minItemsPerRun < 1) {
            throw new \InvalidArgumentException('minItemsPerRun must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'golden_hour';
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

                return \in_array($h, $this->morningHours, true)
                    || \in_array($h, $this->eveningHours, true);
            }
        );

        if (\count($cand) < $this->minItemsPerRun) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf = [];
        $lastTs = null;

        foreach ($cand as $m) {
            $ts = (int) $m->getTakenAt()->getTimestamp();
            if ($lastTs !== null && ($ts - $lastTs) > $this->sessionGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf = [];
            }
            $buf[] = $m;
            $lastTs = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $centroid = MediaMath::centroid($run);
            $time     = MediaMath::timeRange($run);
            $out[] = new ClusterDraft(
                algorithm: 'golden_hour',
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $run)
            );
        }

        return $out;
    }
}
