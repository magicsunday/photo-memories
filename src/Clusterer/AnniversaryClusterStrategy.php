<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups media into anniversary clusters.
 *
 * The strategy scans all provided media and groups those that were taken on the
 * same calendar day, regardless of the year, so that it can highlight "on this
 * day" memories. Each qualifying day must contain at least the configured minimum
 * number of media items to form a cluster. For each resulting cluster the
 * strategy computes descriptive metadata such as a majority place label, a time
 * range, and the geographical centroid of all members using helper methods from
 * {@see ClusterBuildHelperTrait}.
 */
final class AnniversaryClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    /**
     * Creates a new anniversary cluster strategy.
     *
     * @param LocationHelper $locHelper Helper that can derive human readable place labels
     *                                  from media items for the metadata summary.
     */
    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly int $minItems = 3
    ) {
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
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
        /** @var array<string, list<Media>> $byMonthDay */
        $byMonthDay = [];
        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if ($t instanceof DateTimeImmutable) {
                // Index media by month and day so anniversaries match regardless of year.
                $byMonthDay[$t->format('m-d')][] = $m;
            }
        }

        $drafts = [];
        foreach ($byMonthDay as $group) {
            if (\count($group) < $this->minItems) {
                // Ignore sparse groups because they do not produce meaningful anniversary stories.
                continue;
            }
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
