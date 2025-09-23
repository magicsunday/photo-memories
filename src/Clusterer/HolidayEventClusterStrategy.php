<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\Calendar;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds clusters for German (federal) holidays per year (no state-specific).
 * Simple exact-date grouping; minimal dependencies.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 79])]
final class HolidayEventClusterStrategy extends AbstractGroupedClusterStrategy
{
    public function __construct(
        private readonly int $minItems = 8
    ) {
    }

    public function name(): string
    {
        return 'holiday_event';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        $name = Calendar::germanFederalHolidayName($takenAt);
        if ($name === null) {
            return null;
        }

        return $takenAt->format('Y') . ':' . $name . ':' . $takenAt->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        [$yearStr, ,] = \explode(':', $key, 3);

        return [
            'year' => (int) $yearStr,
            'holiday' => 1.0,
        ];
    }
}
