<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function assert;
use function explode;

/**
 * Groups media by meteorological seasons per year (DE).
 * Winter is Dec–Feb (December assigned to next year).
 */
final readonly class SeasonClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        // Minimum members per (season, year) bucket.
        private int $minItemsPerSeason = 20,
    ) {
        if ($this->minItemsPerSeason < 1) {
            throw new InvalidArgumentException('minItemsPerSeason must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'season';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $groups */
        $groups = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $month = (int) $t->format('n');
            $year  = (int) $t->format('Y');

            $season = match (true) {
                $month >= 3 && $month <= 5  => 'Frühling',
                $month >= 6 && $month <= 8  => 'Sommer',
                $month >= 9 && $month <= 11 => 'Herbst',
                default                     => 'Winter',
            };

            // Winter: Dezember gehört zum Winter des Folgejahres (2024-12 ⇒ Winter 2025)
            if ($season === 'Winter' && $month === 12) {
                ++$year;
            }

            $key = $year . ':' . $season;
            $groups[$key] ??= [];
            $groups[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleGroups */
        $eligibleGroups = $this->filterGroupsByMinItems($groups, $this->minItemsPerSeason);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleGroups as $key => $members) {
            [$yearStr, $season] = explode(':', $key, 2);
            $yearInt            = (int) $yearStr;

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
                members: array_map(static fn (Media $m): int => $m->getId(), $members)
            );
        }

        return $out;
    }
}
