<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds New Year's Eve clusters (local night around Dec 31 → Jan 1).
 */
final class NewYearEveClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        /** Hours considered NYE party window (local, 24h). */
        private readonly int $startHour = 20,
        private readonly int $endHour = 2,
        private readonly int $minItems = 6
    ) {
        if ($this->startHour < 0 || $this->startHour > 23 || $this->endHour < 0 || $this->endHour > 23) {
            throw new \InvalidArgumentException('Hour bounds must be within 0..23.');
        }
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
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

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $y = (int) $local->format('Y');
            $md = $local->format('m-d');
            $h  = (int) $local->format('G');

            $isNYEWindow = ($md === '12-31' && $h >= $this->startHour)
                || ($md === '01-01' && $h <= $this->endHour);

            if ($isNYEWindow) {
                $byYear[$y] ??= [];
                $byYear[$y][] = $m;
            }
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($byYear as $y => $list) {
            if (\count($list) < $this->minItems) {
                continue;
            }
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
