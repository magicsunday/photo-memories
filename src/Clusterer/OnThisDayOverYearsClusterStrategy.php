<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Collects all photos taken around today's month/day across different years.
 * Example: Feb-14 across 2014..2025 within a +/- window of days.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 66])]
final class OnThisDayOverYearsClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    private int $anchorMonth = 1;
    private int $anchorDay = 1;

    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $windowDays = 0,   // 0 = exact same month/day, 1..3 = tolerant
        private readonly int $minYears = 3,
        private readonly int $minItems = 12
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'on_this_day_over_years';
    }

    protected function beforeGrouping(): void
    {
        $now = new DateTimeImmutable('now', $this->timezone());
        $this->anchorMonth = (int) $now->format('n');
        $this->anchorDay = (int) $now->format('j');
    }

    protected function groupKey(Media $media): ?string
    {
        $local = $this->localTakenAt($media);
        if ($local === null) {
            return null;
        }
        $monthDistance = $this->monthDayDistance(
            $this->anchorMonth,
            $this->anchorDay,
            (int) $local->format('n'),
            (int) $local->format('j')
        );

        if ($monthDistance > $this->windowDays) {
            return null;
        }

        return 'current_day';
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        $yearsMap = $this->uniqueDateParts($members, 'Y', $this->timezone());
        if (\count($yearsMap) < $this->minYears) {
            return null;
        }

        $years = \array_map('intval', \array_keys($yearsMap));

        return [
            'years' => $years,
        ];
    }

    private function monthDayDistance(int $m1, int $d1, int $m2, int $d2): int
    {
        // Simple absolute distance in days ignoring leap-year wrap; good enough for small windows.
        $a = \strtotime(\sprintf('2001-%02d-%02d', $m1, $d1));
        $b = \strtotime(\sprintf('2001-%02d-%02d', $m2, $d2));
        if ($a === false || $b === false) {
            return 9999;
        }
        return (int) \abs(($b - $a) / 86400);
    }
}
