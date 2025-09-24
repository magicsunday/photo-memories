<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\SeasonHelperTrait;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups media by meteorological seasons per year (DE).
 * Winter is Dec–Feb (December assigned to next year).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 58])]
final class SeasonClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    use SeasonHelperTrait;

    public function __construct(
        private readonly int $minItems = 20,
        string $timezone = 'Europe/Berlin'
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'season';
    }

    /**
     * @param list<Media> $members
     */
    protected function minimumGroupSize(string $key, array $members): int
    {
        return $this->minItems;
    }

    protected function localGroupKey(Media $media, DateTimeImmutable $local): ?string
    {
        $info = $this->seasonInfo($local);

        return $info['seasonYear'] . ':' . $info['season'];
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        [$yearStr, $season] = \explode(':', $key, 2);

        return [
            'label' => $season,
            'year' => (int) $yearStr,
        ];
    }
}
