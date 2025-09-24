<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractFilteredTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects dining-out moments based on evening hours and food/venue keywords; spatially compact sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 52])]
final class DiningOutClusterStrategy extends AbstractFilteredTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'restaurant', 'ristorante', 'trattoria', 'osteria', 'bistro',
        'cafe', 'café', 'bar', 'kneipe', 'brauhaus',
        'dinner', 'lunch', 'brunch', 'food', 'essen', 'speise',
        'sushi', 'pizza', 'burger', 'steak', 'pasta', 'tapas',
        'weingut', 'wine', 'wein', 'biergarten',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 2 * 3600,
        private readonly float $radiusMeters = 250.0,
        int $minItems = 4
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'dining_out';
    }

    protected function keywords(): array
    {
        return self::KEYWORDS;
    }

    protected function passesContextFilters(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');

        return $hour >= 17 && $hour <= 23;
    }

    protected function sessionRadiusMeters(): ?float
    {
        return $this->radiusMeters;
    }
}
