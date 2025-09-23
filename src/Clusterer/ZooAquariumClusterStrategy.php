<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters "Zoo & Aquarium" moments using filename/path keywords and compact time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 73])]
final class ZooAquariumClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'zoo', 'tierpark', 'wildpark', 'safari park', 'aquarium', 'sealife', 'sea life', 'zoopark',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $sessionGapSeconds = 2 * 3600,
        private readonly float $radiusMeters = 400.0,
        int $minItems = 5
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'zoo_aquarium';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');
        if ($hour < 9 || $hour > 20) {
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
