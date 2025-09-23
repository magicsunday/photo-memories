<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds one macro cluster per year if enough items exist.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 60])]
final class YearInReviewClusterStrategy extends AbstractGroupedClusterStrategy
{
    public function __construct(
        private readonly int $minItems = 150,
        private readonly int $minDistinctMonths = 5
    ) {
    }

    public function name(): string
    {
        return 'year_in_review';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $takenAt->format('Y');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        $monthsMap = $this->uniqueDateParts($members, 'n');
        if (\count($monthsMap) < $this->minDistinctMonths) {
            return null;
        }

        return [
            'year' => (int) $key,
        ];
    }
}
