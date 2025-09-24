<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

final class AnniversaryClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly LocationHelper $locHelper
    ) {
    }

    public function name(): string
    {
        return 'anniversary';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $byMonthDay */
        $byMonthDay = [];
        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if ($t instanceof DateTimeImmutable) {
                $byMonthDay[$t->format('m-d')][] = $m;
            }
        }

        $drafts = [];
        foreach ($byMonthDay as $group) {
            if (\count($group) < 3) {
                continue;
            }
            $label = $this->locHelper->majorityLabel($group);

            $params = [
                'time_range' => $this->computeTimeRange($group),
            ];
            if ($label !== null) {
                $params['place'] = $label;
            }

            $drafts[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: $this->computeCentroid($group),
                members: $this->toMemberIds($group)
            );
        }

        return $drafts;
    }
}
