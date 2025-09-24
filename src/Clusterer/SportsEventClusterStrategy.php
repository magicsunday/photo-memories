<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractFilteredTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Sports events based on keywords (stadium/match/club names) and weekend bias.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 78])]
final class SportsEventClusterStrategy extends AbstractFilteredTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'stadion', 'arena', 'sportpark', 'eishalle',
        'match', 'spiel', 'game', 'derby',
        'fussball', 'fußball', 'football', 'soccer',
        'handball', 'basketball', 'eishockey', 'hockey',
        'tennis', 'marathon', 'lauf', 'run', 'triathlon',
        'bundesliga', 'dfb', 'uefa', 'champions', 'cup',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 3 * 3600,
        private readonly float $radiusMeters = 500.0,
        int $minItems = 5,
        private readonly bool $preferWeekend = true
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'sports_event';
    }

    protected function keywords(): array
    {
        return self::KEYWORDS;
    }

    protected function passesContextFilters(Media $media, DateTimeImmutable $local): bool
    {
        if (!$this->preferWeekend) {
            return true;
        }

        $dow = (int) $local->format('N');
        if ($dow === 6 || $dow === 7) {
            return true;
        }

        // Keep weekday shots for leniency, mirroring the previous implementation.
        return true;
    }

    protected function sessionRadiusMeters(): ?float
    {
        return $this->radiusMeters;
    }
}
