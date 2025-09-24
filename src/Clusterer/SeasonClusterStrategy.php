<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\SeasonHelperTrait;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups media by meteorological seasons per year (DE).
 * Winter is Dec–Feb (December assigned to next year).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 58])]
final class SeasonClusterStrategy extends AbstractGroupedClusterStrategy
{
    use SeasonHelperTrait;

    public function __construct(
        private readonly int $minItems = 20
    ) {
    }

    public function name(): string
    {
        return 'season';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        $info = $this->seasonInfo($takenAt);

        return $info['seasonYear'] . ':' . $info['season'];
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        [$yearStr, $season] = \explode(':', $key, 2);

        return [
            'label' => $season,
            'year' => (int) $yearStr,
        ];
    }
}
