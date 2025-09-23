<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractKeywordConsecutiveRunOverYearsStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best multi-day winter snow vacation per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 63])]
final class SnowVacationOverYearsClusterStrategy extends AbstractKeywordConsecutiveRunOverYearsStrategy
{
    /** @var list<string> */
    private const KEYWORDS = ['schnee', 'snow', 'ski', 'langlauf', 'skitour', 'snowboard', 'piste', 'gondel', 'lift', 'alpen', 'hütte', 'huette'];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 4,
        int $minNights = 3,
        int $maxNights = 14,
        int $minYears = 3,
        int $minItemsTotal = 30
    ) {
        parent::__construct($timezone, $minNights, $maxNights, $minItemsPerDay, $minYears, $minItemsTotal);
    }

    public function name(): string
    {
        return 'snow_vacation_over_years';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        if (!parent::shouldConsider($media, $local)) {
            return false;
        }

        $month = (int) $local->format('n');
        return $month === 12 || $month <= 2;
    }

    /**
     * @return list<string>
     */
    protected function keywords(): array
    {
        return self::KEYWORDS;
    }
}
