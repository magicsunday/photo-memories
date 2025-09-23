<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractKeywordConsecutiveRunOverYearsStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best multi-day camping run per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 63])]
final class CampingOverYearsClusterStrategy extends AbstractKeywordConsecutiveRunOverYearsStrategy
{
    /** @var list<string> */
    private const KEYWORDS = ['camping', 'zelt', 'zelten', 'wohnmobil', 'caravan', 'wohnwagen', 'campground', 'camp site', 'campsite', 'stellplatz'];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 3,
        int $minNights = 2,
        int $maxNights = 14,
        int $minYears = 3,
        int $minItemsTotal = 24
    ) {
        parent::__construct($timezone, $minNights, $maxNights, $minItemsPerDay, $minYears, $minItemsTotal);
    }

    public function name(): string
    {
        return 'camping_over_years';
    }

    /**
     * @return list<string>
     */
    protected function keywords(): array
    {
        return self::KEYWORDS;
    }
}
