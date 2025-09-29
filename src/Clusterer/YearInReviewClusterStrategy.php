<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds one macro cluster per year if enough items exist.
 */
final readonly class YearInReviewClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private int $minItemsPerYear = 150,
        private int $minDistinctMonths = 5
    ) {
        if ($this->minItemsPerYear < 1) {
            throw new InvalidArgumentException('minItemsPerYear must be >= 1.');
        }

        if ($this->minDistinctMonths < 1 || $this->minDistinctMonths > 12) {
            throw new InvalidArgumentException('minDistinctMonths must be within 1..12.');
        }
    }

    public function name(): string
    {
        return 'year_in_review';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<int, list<Media>> $byYear */
        $byYear = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            $y = (int) $t->format('Y');
            $byYear[$y] ??= [];
            $byYear[$y][] = $m;
        }

        $eligibleYears = $this->filterGroupsByMinItems($byYear, $this->minItemsPerYear);

        $eligibleYears = $this->filterGroups(
            $eligibleYears,
            function (array $list): bool {

                /** @var array<int,bool> $months */
                $months = [];
                foreach ($list as $m) {
                    $months[(int) $m->getTakenAt()->format('n')] = true;
                }

                $count = \count($months);
                return $count >= $this->minDistinctMonths;
            }
        );

        if ($eligibleYears === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleYears as $year => $list) {
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'year'       => $year,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
