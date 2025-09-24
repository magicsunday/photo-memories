<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds a highlight memory for each (year, month) with sufficient coverage.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 59])]
final class MonthlyHighlightsClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 40,
        private readonly int $minDistinctDays = 10
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'monthly_highlights';
    }

    protected function localGroupKey(Media $media, DateTimeImmutable $local): ?string
    {
        return $local->format('Y-m');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        $days = $this->uniqueLocalDateParts($members, 'Y-m-d');
        if (\count($days) < $this->minDistinctDays) {
            return null;
        }

        return [
            'year' => (int) \substr($key, 0, 4),
            'month' => (int) \substr($key, 5, 2),
        ];
    }

}
