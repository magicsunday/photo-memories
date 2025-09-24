<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractFilteredTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds "Snow Day" clusters using winter months and snow/ski keywords.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 55])]
final class SnowDayClusterStrategy extends AbstractFilteredTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = ['schnee', 'snow', 'ski', 'langlauf', 'skitour', 'snowboard', 'piste', 'eiszapfen'];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 2 * 3600,
        int $minItems = 6
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'snow_day';
    }

    protected function keywords(): array
    {
        return self::KEYWORDS;
    }

    protected function passesContextFilters(Media $media, DateTimeImmutable $local): bool
    {
        $month = (int) $local->format('n');

        return $month === 12 || $month <= 2;
    }
}
