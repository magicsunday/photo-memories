<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

/**
 * Groups media into anniversary clusters.
 *
 * The strategy scans all provided media and groups those that were taken on the
 * same calendar day, regardless of the year, so that it can highlight "on this
 * day" memories. Each qualifying day must contain at least the configured minimum
 * number of media items to form a cluster. Optional guards can demand a minimum
 * amount of distinct years, and a configurable cap keeps only the most
 * meaningful anniversaries based on a scoring model that favours recurring and
 * media-rich days. For each resulting cluster the strategy computes descriptive
 * metadata such as a majority place label, a time range, and the geographical
 * centroid of all members using helper methods from {@see ClusterBuildHelperTrait}.
 */
final class AnniversaryClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use MediaFilterTrait;

    /**
     * Creates a new anniversary cluster strategy.
     *
     * @param LocationHelper $locHelper Helper that can derive human readable place labels
     *                                  from media items for the metadata summary.
     */
    public function __construct(
        private readonly LocationHelper $locHelper,
        // Minimum media items per anniversary bucket before scoring kicks in.
        private readonly int $minItemsPerAnniversary = 3,
        private readonly int $minDistinctYears = 1,
        private readonly int $maxClusters = 0
    ) {
        if ($this->minItemsPerAnniversary < 1) {
            throw new \InvalidArgumentException('minItemsPerAnniversary must be >= 1.');
        }

        if ($this->minDistinctYears < 1) {
            throw new \InvalidArgumentException('minDistinctYears must be >= 1.');
        }

        if ($this->maxClusters < 0) {
            throw new \InvalidArgumentException('maxClusters must be >= 0.');
        }
    }

    /**
     * Returns the unique identifier for this strategy.
     *
     * @return string Identifier that is used to mark generated clusters.
     */
    public function name(): string
    {
        return 'anniversary';
    }

    /**
     * Builds clusters for media items that share the same anniversary.
     *
     * The items are grouped by the month-day portion of their {@see Media::getTakenAt}
     * timestamp. Only groups containing at least the configured minimum number of
     * media entities are considered meaningful anniversaries and therefore turned into clusters. Each cluster draft
     * aggregates descriptive metadata: the majority place label, the time range covering
     * all members, the geographic centroid, and the list of media identifiers.
     *
     * @param list<Media> $items Media items that should be evaluated for anniversary clusters.
     *
     * @return list<ClusterDraft> Draft clusters that summarize the anniversary groups.
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $byMonthDay */
        $byMonthDay = [];
        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            // Index media by month and day so anniversaries match regardless of year.
            $byMonthDay[$t->format('m-d')][] = $m;
        }

        $eligibleGroups = $this->filterGroupsByMinItems($byMonthDay, $this->minItemsPerAnniversary);

        $scoredGroups = [];

        foreach ($eligibleGroups as $group) {
            $years = [];
            foreach ($group as $media) {
                $takenAt = $media->getTakenAt();
                if ($takenAt instanceof DateTimeImmutable) {
                    $year = (int) $takenAt->format('Y');
                    $years[$year] = ($years[$year] ?? 0) + 1;
                }
            }

            $distinctYears = \count($years);
            if ($distinctYears < $this->minDistinctYears) {
                continue;
            }

            $total = \count($group);
            $spanYears = 0;
            if ($distinctYears > 0) {
                /** @var list<int> $yearKeys */
                $yearKeys = array_keys($years);
                $spanYears = max($yearKeys) - min($yearKeys) + 1;
            }

            $maxPerYear = $years === [] ? 0 : max($years);
            $averagePerYear = $distinctYears === 0 ? 0.0 : $total / $distinctYears;

            // Weight recurring anniversaries stronger than one-off bursts while still
            // favouring days that contain many media overall.
            $score = (int) round(
                ($total * 5)
                + ($distinctYears * 10)
                + ($averagePerYear * 3)
                + ($spanYears * 2)
                + $maxPerYear
            );

            $scoredGroups[] = [
                'group' => $group,
                'score' => $score,
            ];
        }

        usort(
            $scoredGroups,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
        );

        if ($this->maxClusters > 0 && \count($scoredGroups) > $this->maxClusters) {
            $scoredGroups = \array_slice($scoredGroups, 0, $this->maxClusters);
        }

        $drafts = [];
        foreach ($scoredGroups as $entry) {
            /** @var list<Media> $group */
            $group = $entry['group'];
            $label = $this->locHelper->majorityLabel($group);

            $params = [
                'time_range' => $this->computeTimeRange($group),
            ];
            if ($label !== null) {
                // Only include a place label when there is a clear majority location.
                $params['place'] = $label;
            }

            $drafts[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                // The helper trait determines the centroid and member identifiers.
                centroid: $this->computeCentroid($group),
                members: $this->toMemberIds($group)
            );
        }

        return $drafts;
    }
}
