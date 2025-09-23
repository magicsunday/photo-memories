<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractKeywordBestDayOverYearsStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best "beach day" per year (based on filename keywords) and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 63])]
final class BeachOverYearsClusterStrategy extends AbstractKeywordBestDayOverYearsStrategy
{
    /** @var list<string> */
    private const KEYWORDS = ['strand', 'beach', 'meer', 'ocean', 'küste', 'kueste', 'coast', 'seaside', 'baltic', 'ostsee', 'nordsee', 'adriatic'];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 6,
        int $minYears = 3,
        int $minItemsTotal = 24
    ) {
        parent::__construct($timezone, $minItemsPerDay, $minYears, $minItemsTotal);
    }

    public function name(): string
    {
        return 'beach_over_years';
    }

    /**
     * @return list<string>
     */
    protected function keywords(): array
    {
        return self::KEYWORDS;
    }
}
