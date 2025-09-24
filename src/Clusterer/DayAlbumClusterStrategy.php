<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups photos by local calendar day. Produces compact "Day Tour" clusters.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 53])]
final class DayAlbumClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 8
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'day_album';
    }

    protected function groupKey(Media $media): ?string
    {
        $local = $this->localTakenAt($media);
        if ($local === null) {
            return null;
        }

        return $local->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        return [
            'year' => (int) \substr($key, 0, 4),
        ];
    }
}
