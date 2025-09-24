<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

final class DeviceSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly int $minItems = 5,
    ) {
    }

    public function name(): string
    {
        return 'device_similarity';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $groups */
        $groups = [];

        /** @var array<string, string> $devices */
        $devices = [];

        foreach ($items as $m) {
            $date   = $m->getTakenAt() instanceof DateTimeImmutable ? $m->getTakenAt()->format('Y-m-d') : 'ohne-datum';
            $device = $m->getCameraModel() ?? 'unbekannt';
            $locKey = $this->locHelper->localityKeyForMedia($m) ?? 'noloc';

            $key = $device.'|'.$date.'|'.$locKey;
            $groups[$key] = $groups[$key] ?? [];
            $groups[$key][] = $m;
            $devices[$key] = $device;
        }

        $drafts = [];
        foreach ($groups as $key => $group) {
            if (\count($group) < $this->minItems) {
                continue;
            }
            $label = $this->locHelper->majorityLabel($group);
            $params = [
                'time_range' => $this->computeTimeRange($group),
                'device' => $devices[$key] ?? 'Unbekannt',
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
