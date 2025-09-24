<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aggregates all items from the current month across different years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 178])]
final class ThisMonthOverYearsClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    private int $currentMonth = 1;

    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $minYears = 3,
        private readonly int $minItems = 24,
        private readonly int $minDistinctDays = 8
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'this_month_over_years';
    }

    protected function beforeGrouping(): void
    {
        $now = new DateTimeImmutable('now', $this->timezone());
        $this->currentMonth = (int) $now->format('n');
    }

    protected function groupKey(Media $media): ?string
    {
        $local = $this->localTakenAt($media);
        if ($local === null) {
            return null;
        }
        if ((int) $local->format('n') !== $this->currentMonth) {
            return null;
        }

        return 'current_month';
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        $yearsMap = $this->uniqueDateParts($members, 'Y', $this->timezone());
        if (\count($yearsMap) < $this->minYears) {
            return null;
        }

        $daysMap = $this->uniqueDateParts($members, 'Y-m-d', $this->timezone());
        if (\count($daysMap) < $this->minDistinctDays) {
            return null;
        }

        return [
            'month' => $this->currentMonth,
            'years' => \array_map('intval', \array_keys($yearsMap)),
        ];
    }
}
