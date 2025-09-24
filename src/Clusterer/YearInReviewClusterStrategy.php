<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds one macro cluster per year if enough items exist.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 60])]
final class YearInReviewClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    public function __construct(
        private readonly int $minItems = 150,
        private readonly int $minDistinctMonths = 5,
        string $timezone = 'Europe/Berlin'
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'year_in_review';
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
        return $local->format('Y');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        $monthsMap = $this->uniqueLocalDateParts($members, 'n');
        if (\count($monthsMap) < $this->minDistinctMonths) {
            return null;
        }

        return [
            'year' => (int) $key,
        ];
    }
}
