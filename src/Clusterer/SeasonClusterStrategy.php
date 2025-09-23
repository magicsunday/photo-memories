<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups media by meteorological seasons per year (DE).
 * Winter is Dec–Feb (December assigned to next year).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 58])]
final class SeasonClusterStrategy extends AbstractGroupedClusterStrategy
{
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

        $month = (int) $takenAt->format('n');
        $year  = (int) $takenAt->format('Y');

        $season = match (true) {
            $month >= 3 && $month <= 5  => 'Frühling',
            $month >= 6 && $month <= 8  => 'Sommer',
            $month >= 9 && $month <= 11 => 'Herbst',
            default => 'Winter',
        };

        if ($season === 'Winter' && $month === 12) {
            $year += 1;
        }

        return $year . ':' . $season;
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
