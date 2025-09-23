<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * City night sessions: night hours & urban keywords, spatially compact.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 76])]
final class CityscapeNightClusterStrategy extends AbstractTimeGapClusterStrategy
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

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');
        if ($hour < 20 && $hour > 2) {
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
