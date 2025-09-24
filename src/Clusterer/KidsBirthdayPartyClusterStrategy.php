<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractFilteredTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects kids' birthday parties based on keywords; compact time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 72])]
final class KidsBirthdayPartyClusterStrategy extends AbstractFilteredTimeGapClusterStrategy
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

    protected function keywords(): array
    {
        return self::KEYWORDS;
    }

    protected function passesContextFilters(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');

        return $hour >= 10 && $hour <= 21;
    }

    protected function sessionRadiusMeters(): ?float
    {
        return $this->radiusMeters;
    }
}
