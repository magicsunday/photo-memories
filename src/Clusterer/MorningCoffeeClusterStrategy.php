<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Morning café/breakfast moments based on time and keywords, spatially compact.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 51])]
final class MorningCoffeeClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'cafe', 'café', 'coffee', 'kaffee', 'frühstück', 'fruehstueck',
        'bakery', 'bäckerei', 'baeckerei', 'brunch', 'espresso', 'barista',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 90 * 60,
        private readonly float $radiusMeters = 200.0,
        int $minItems = 3
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'morning_coffee';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');
        if ($hour < 7 || $hour > 10) {
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
