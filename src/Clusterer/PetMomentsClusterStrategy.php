<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Heuristic pet moments based on path keywords; grouped into time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 49])]
final class PetMomentsClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'dog', 'dogs', 'hund', 'hunde', 'welpe', 'puppy',
        'cat', 'cats', 'katze', 'kater', 'kitten',
        'hamster', 'kaninchen', 'bunny', 'rabbit',
        'meerschwein', 'guinea', 'pony', 'pferd',
        'haustier', 'pet', 'tierpark', 'zoo',
    ];

    public function __construct(
        int $sessionGapSeconds = 2 * 3600,
        int $minItems = 6
    ) {
        parent::__construct('UTC', $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'pet_moments';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return $this->mediaMatchesKeywords($media, self::KEYWORDS);
    }
}
