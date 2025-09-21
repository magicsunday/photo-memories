<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\Calendar;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds clusters for German (federal) holidays per year (no state-specific).
 * Simple exact-date grouping; minimal dependencies.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 79])]
final class HolidayEventClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $minItems = 8
    ) {
    }

    public function name(): string
    {
        return 'holiday_event';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $groups */
        $groups = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $name = Calendar::germanFederalHolidayName($t);
            if ($name === null) {
                continue;
            }
            $key = $t->format('Y') . ':' . $name . ':' . $t->format('Y-m-d');
            $groups[$key] ??= [];
            $groups[$key][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($groups as $key => $members) {
            if (\count($members) < $this->minItems) {
                continue;
            }

            [$yearStr, $name,] = \explode(':', $key, 3);
            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'year'       => (int) $yearStr,
                    'holiday'    => 1.0,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );
        }

        return $out;
    }
}
