<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 64])]
final class AnniversaryClusterStrategy extends AbstractGroupedClusterStrategy
{
    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly int $minItems = 3
    ) {
    }

    public function name(): string
    {
        return 'anniversary';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $takenAt->format('m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        $label = $this->locHelper->majorityLabel($members);

        $params = [];
        if ($label !== null) {
            $params['place'] = $label;
        }

        return $params;
    }
}
