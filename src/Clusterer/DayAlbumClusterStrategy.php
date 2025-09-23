<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups photos by local calendar day. Produces compact "Day Tour" clusters.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 53])]
final class DayAlbumClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 8
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function name(): string
    {
        return 'day_album';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $takenAt->setTimezone($this->timezone)->format('Y-m-d');
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
