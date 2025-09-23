<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Outdoor festival/open-air sessions in summer months.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 77])]
final class FestivalSummerClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'festival', 'open air', 'openair', 'rock am ring', 'wacken',
        'lollapalooza', 'fusion festival', 'parookaville', 'deichbrand',
        'bühne', 'buehne', 'stage', 'headliner',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 3 * 3600,
        private readonly float $radiusMeters = 600.0,
        int $minItems = 8
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'festival_summer';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $month = (int) $local->format('n');
        if ($month < 6 || $month > 9) {
            return false;
        }

        $hour = (int) $local->format('G');
        if ($hour > 2 && $hour < 14) {
            return false;
        }

        return $this->mediaMatchesKeywords($media, self::KEYWORDS);
    }

    /**
     * @param list<Media> $members
     */
    protected function isSessionValid(array $members): bool
    {
        return parent::isSessionValid($members)
            && $this->allWithinRadius($members, $this->radiusMeters);
    }
}
