<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\AbstractConsecutiveRunOverYearsStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best weekend getaway (1..3 nights) per year and aggregates them into one over-years memory.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 61])]
final class WeekendGetawaysOverYearsClusterStrategy extends AbstractConsecutiveRunOverYearsStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minNights = 1,
        int $maxNights = 3,
        int $minItemsPerDay = 4,
        int $minYears = 3,
        int $minItemsTotal = 24
    ) {
        if ($minNights < 1) {
            throw new InvalidArgumentException('minNights must be >= 1.');
        }

        parent::__construct($timezone, $minNights, $maxNights, $minItemsPerDay, $minYears, $minItemsTotal);
    }

    public function name(): string
    {
        return 'weekend_getaways_over_years';
    }

    protected function isRunValid(array $run, array $daysMap): bool
    {
        return $this->containsWeekendDay($run['days']);
    }
}
