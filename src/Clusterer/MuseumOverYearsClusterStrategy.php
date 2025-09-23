<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractKeywordBestDayOverYearsStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks best museum day per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 62])]
final class MuseumOverYearsClusterStrategy extends AbstractKeywordBestDayOverYearsStrategy
{
    /** @var list<string> */
    private const KEYWORDS = ['museum', 'galerie', 'gallery', 'ausstellung', 'exhibit', 'exhibition', 'kunsthalle'];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 5,
        int $minYears = 3,
        int $minItemsTotal = 18
    ) {
        parent::__construct($timezone, $minItemsPerDay, $minYears, $minItemsTotal);
    }

    public function name(): string
    {
        return 'museum_over_years';
    }

    /**
     * @return list<string>
     */
    protected function keywords(): array
    {
        return self::KEYWORDS;
    }
}
