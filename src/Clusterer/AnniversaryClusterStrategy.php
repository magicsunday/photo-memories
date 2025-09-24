<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\PlaceLabelHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 64])]
final class AnniversaryClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    use PlaceLabelHelperTrait;

    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly int $minItems = 3,
        string $timezone = 'Europe/Berlin'
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'anniversary';
    }

    /**
     * @param list<Media> $members
     */
    protected function minimumGroupSize(string $key, array $members): int
    {
        return $this->minItems;
    }

    protected function localGroupKey(Media $media, DateTimeImmutable $local): ?string
    {
        return $local->format('m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        return $this->withMajorityPlace($members);
    }
}
