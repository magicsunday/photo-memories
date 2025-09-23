<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractConsecutiveRunClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Multi-day camping runs (consecutive days) using keywords.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 83])]
final class CampingTripClusterStrategy extends AbstractConsecutiveRunClusterStrategy
{
    /** @var list<string> */
    private const KEYWORDS = [
        'camping',
        'zelt',
        'zelten',
        'wohnmobil',
        'caravan',
        'wohnwagen',
        'campground',
        'camp site',
        'campsite',
        'stellplatz',
    ];

    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 3,
        int $minNights = 2,
        int $maxNights = 14,
        int $minItemsTotal = 20
    ) {
        parent::__construct($timezone, $minItemsPerDay, $minItemsTotal, $minNights, $maxNights);
    }

    public function name(): string
    {
        return 'camping_trip';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return $this->mediaMatchesKeywords($media, self::KEYWORDS);
    }
}
