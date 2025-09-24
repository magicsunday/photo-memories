<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds New Year's Eve clusters (local night around Dec 31 → Jan 1).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 79])]
final class NewYearEveClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        /** Hours considered NYE party window (local, 24h). */
        private readonly int $startHour = 20,
        private readonly int $endHour = 2,
        private readonly int $minItems = 6
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'new_year_eve';
    }

    protected function groupKey(Media $media): ?string
    {
        $local = $this->localTakenAt($media);
        if ($local === null) {
            return null;
        }
        $monthDay = $local->format('m-d');
        $hour = (int) $local->format('G');

        $inWindow = ($monthDay === '12-31' && $hour >= $this->startHour)
            || ($monthDay === '01-01' && $hour <= $this->endHour);

        if (!$inWindow) {
            return null;
        }

        return $local->format('Y');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        return [
            'year' => (int) $key,
        ];
    }
}
