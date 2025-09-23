<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Heuristic "Golden Hour" clusters around morning/evening hours.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 57])]
final class GoldenHourClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /**
     * @param list<int> $morningHours
     * @param list<int> $eveningHours
     */
    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly array $morningHours = [6, 7, 8],
        private readonly array $eveningHours = [18, 19, 20],
        int $sessionGapSeconds = 90 * 60,
        int $minItems = 5
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'golden_hour';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');

        return \in_array($hour, $this->morningHours, true)
            || \in_array($hour, $this->eveningHours, true);
    }
}
