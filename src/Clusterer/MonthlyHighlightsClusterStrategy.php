<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds a highlight memory for each (year, month) with sufficient coverage.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 59])]
final class MonthlyHighlightsClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 40,
        private readonly int $minDistinctDays = 10
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function name(): string
    {
        return 'monthly_highlights';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $takenAt->setTimezone($this->timezone)->format('Y-m');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        $days = $this->uniqueDateParts($members, 'Y-m-d', $this->timezone);
        if (\count($days) < $this->minDistinctDays) {
            return null;
        }

        return [
            'year' => (int) \substr($key, 0, 4),
            'month' => (int) \substr($key, 5, 2),
        ];
    }

    private static function germanMonthLabel(int $m): string
    {
        return match ($m) {
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
            default => 'Monat',
        };
    }
}
