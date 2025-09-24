<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractFilteredTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * City night sessions: night hours & urban keywords, spatially compact.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 76])]
final class CityscapeNightClusterStrategy extends AbstractFilteredTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'city', 'urban', 'downtown', 'skyline', 'hochhaus', 'skyscraper', 'street', 'straße', 'strasse', 'platz',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 2 * 3600,
        private readonly float $radiusMeters = 350.0,
        int $minItems = 5
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'cityscape_night';
    }

    protected function keywords(): array
    {
        return self::KEYWORDS;
    }

    protected function passesContextFilters(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');

        return $hour >= 20 || $hour <= 2;
    }

    protected function sessionRadiusMeters(): ?float
    {
        return $this->radiusMeters;
    }
}
