<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Groups media by meteorological seasons per year (DE).
 * Winter is Dec–Feb (December assigned to next year).
 */
final class SeasonClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $minItems = 20
    ) {
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'season';
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
            $month = (int) $t->format('n');
            $year  = (int) $t->format('Y');

            $season = match (true) {
                $month >= 3 && $month <= 5  => 'Frühling',
                $month >= 6 && $month <= 8  => 'Sommer',
                $month >= 9 && $month <= 11 => 'Herbst',
                default => 'Winter',
            };

            // Winter: Dezember gehört zum Winter des Folgejahres (2024-12 ⇒ Winter 2025)
            if ($season === 'Winter' && $month === 12) {
                $year += 1;
            }

            $key = $year . ':' . $season;
            $groups[$key] ??= [];
            $groups[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleGroups */
        $eligibleGroups = \array_filter(
            $groups,
            fn (array $members): bool => \count($members) >= $this->minItems
        );

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleGroups as $key => $members) {

            [$yearStr, $season] = \explode(':', $key, 2);
            $yearInt = (int) $yearStr;

            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'label'      => $season,
                    'year'       => $yearInt,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );
        }

        return $out;
    }
}
