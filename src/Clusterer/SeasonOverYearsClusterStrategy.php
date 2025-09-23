<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aggregates each season across multiple years into a memory
 * (e.g., "Sommer im Laufe der Jahre").
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 62])]
final class SeasonOverYearsClusterStrategy extends AbstractGroupedClusterStrategy
{
    public function __construct(
        private readonly int $minYears = 3,
        private readonly int $minItems = 30
    ) {
    }

    public function name(): string
    {
        return 'season_over_years';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        $month = (int) $takenAt->format('n');

        return match (true) {
            $month >= 3 && $month <= 5  => 'Frühling',
            $month >= 6 && $month <= 8  => 'Sommer',
            $month >= 9 && $month <= 11 => 'Herbst',
            default => 'Winter',
        };
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        $yearsMap = $this->uniqueDateParts($members, 'Y');
        if (\count($yearsMap) < $this->minYears) {
            return null;
        }

        return [
            'label' => $key . ' im Laufe der Jahre',
            'years' => \array_map('intval', \array_keys($yearsMap)),
        ];
    }
}
