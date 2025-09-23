<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects kids' birthday parties based on keywords; compact time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 72])]
final class KidsBirthdayPartyClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'geburtstag', 'birthday', 'party', 'kinder', 'kids', 'kerzen',
        'torte', 'kuchen', 'luftballon', 'balloon', 'geschenke',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 3 * 3600,
        private readonly float $radiusMeters = 250.0,
        int $minItems = 6
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'kids_birthday_party';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');
        if ($hour < 10 || $hour > 21) {
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
