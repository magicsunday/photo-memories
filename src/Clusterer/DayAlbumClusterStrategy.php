<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Groups photos by local calendar day. Produces compact "Day Tour" clusters.
 */
final class DayAlbumClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 8
    ) {
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'day_album';
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
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleDays */
        $eligibleDays = \array_filter(
            $byDay,
            fn (array $members): bool => \count($members) >= $this->minItems
        );

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleDays as $key => $members) {

            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'year'       => (int) \substr($key, 0, 4),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );
        }

        return $out;
    }
}
