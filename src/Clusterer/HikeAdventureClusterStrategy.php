<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups hiking/adventure sessions based on keywords; validates by traveled distance if GPS is available.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 74])]
final class HikeAdventureClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'wander', 'wanderung', 'trail', 'hike', 'hiking', 'gipfel',
        'alpen', 'dolomiten', 'pass', 'berg', 'berge', 'klettersteig',
    ];

    public function __construct(
        int $sessionGapSeconds = 3 * 3600,
        private readonly float $minDistanceKm = 6.0,
        int $minItems = 6,
        private readonly int $minItemsNoGps = 12
    ) {
        parent::__construct('UTC', $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'hike_adventure';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return $this->mediaMatchesKeywords($media, self::KEYWORDS);
    }

    /**
     * @param list<Media> $members
     */
    protected function isSessionValid(array $members): bool
    {
        if (!parent::isSessionValid($members)) {
            return false;
        }

        $withGps = \array_values(\array_filter(
            $members,
            static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null
        ));

        if ($withGps === []) {
            return \count($members) >= $this->minItemsNoGps;
        }

        \usort(
            $withGps,
            static fn (Media $a, Media $b): int => $a->getTakenAt()->getTimestamp() <=> $b->getTakenAt()->getTimestamp()
        );

        $distanceKm = 0.0;
        for ($i = 1, $n = \count($withGps); $i < $n; $i++) {
            $prev = $withGps[$i - 1];
            $curr = $withGps[$i];
            $distanceKm += MediaMath::haversineDistanceInMeters(
                (float) $prev->getGpsLat(),
                (float) $prev->getGpsLon(),
                (float) $curr->getGpsLat(),
                (float) $curr->getGpsLon()
            ) / 1000.0;
        }

        return $distanceKm >= $this->minDistanceKm;
    }
}
