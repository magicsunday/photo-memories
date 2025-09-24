<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\PlaceLabelHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 30])]
final class DeviceSimilarityStrategy extends AbstractGroupedClusterStrategy
{
    use PlaceLabelHelperTrait;

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
     * @param list<Media> $members
     */
    protected function minimumGroupSize(string $key, array $members): int
    {
        return $this->minItems;
    }

    protected function groupKey(Media $media): ?string
    {
        $date = $media->getTakenAt() instanceof DateTimeImmutable
            ? $media->getTakenAt()->format('Y-m-d')
            : 'ohne-datum';

        $device = $media->getCameraModel() ?? 'unbekannt';
        $locKey = $this->locHelper->localityKeyForMedia($media) ?? 'noloc';

        return $device . '|' . $date . '|' . $locKey;
    }

    protected function groupParams(string $key, array $members): ?array
    {
        return $this->withMajorityPlace($members);
    }
}
