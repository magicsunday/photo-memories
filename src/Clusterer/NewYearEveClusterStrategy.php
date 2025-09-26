<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds New Year's Eve clusters (local night around Dec 31 → Jan 1).
 */
final class NewYearEveClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        /** Hours considered NYE party window (local, 24h). */
        private readonly int $startHour = 20,
        private readonly int $endHour = 2,
        // Minimum media per year-long NYE bucket before emitting a memory.
        private readonly int $minItemsPerYear = 6
    ) {
        if ($this->startHour < 0 || $this->startHour > 23 || $this->endHour < 0 || $this->endHour > 23) {
            throw new \InvalidArgumentException('Hour bounds must be within 0..23.');
        }
        if ($this->minItemsPerYear < 1) {
            throw new \InvalidArgumentException('minItemsPerYear must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'new_year_eve';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<int, list<Media>> $byYear */
        $byYear = [];

        $nyeItems = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m) use ($tz): bool {
                $takenAt = $m->getTakenAt();
                \assert($takenAt instanceof DateTimeImmutable);
                $local = $takenAt->setTimezone($tz);
                $md    = $local->format('m-d');
                $hour  = (int) $local->format('G');

                return ($md === '12-31' && $hour >= $this->startHour)
                    || ($md === '01-01' && $hour <= $this->endHour);
            }
        );

        foreach ($nyeItems as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            $local = $t->setTimezone($tz);
            $y     = (int) $local->format('Y');

            $byYear[$y] ??= [];
            $byYear[$y][] = $m;
        }

        $eligibleYears = $this->filterGroupsByMinItems($byYear, $this->minItemsPerYear);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleYears as $y => $list) {
            \usort($list, static fn(Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'year'       => $y,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float)$centroid['lat'], 'lon' => (float)$centroid['lon']],
                members: \array_map(static fn(Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
