<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\Calendar;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds clusters for German (federal) holidays per year (no state-specific).
 * Simple exact-date grouping; minimal dependencies.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 79])]
final class HolidayEventClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    public function __construct(
        private readonly int $minItems = 8,
        string $timezone = 'Europe/Berlin'
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'holiday_event';
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
        $name = Calendar::germanFederalHolidayName($local);
        if ($name === null) {
            return null;
        }

        return $local->format('Y') . ':' . $name . ':' . $local->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        [$yearStr, ,] = \explode(':', $key, 3);

        return [
            'year' => (int) $yearStr,
            'holiday' => 1.0,
        ];
    }
}
