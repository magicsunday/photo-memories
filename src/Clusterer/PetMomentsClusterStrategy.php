<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractFilteredTimeGapClusterStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Heuristic pet moments based on path keywords; grouped into time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 49])]
final class PetMomentsClusterStrategy extends AbstractFilteredTimeGapClusterStrategy
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

    protected function keywords(): array
    {
        return self::KEYWORDS;
    }
}
