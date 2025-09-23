<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aggregates all items from the current month across different years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 178])]
final class ThisMonthOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minYears = 3,
        private readonly int $minItems = 24,
        private readonly int $minDistinctDays = 8
    ) {
    }

    public function name(): string
    {
        return 'this_month_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz   = new DateTimeZone($this->timezone);
        $now  = new DateTimeImmutable('now', $tz);
        $mon  = (int) $now->format('n');

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];
        /** @var array<string,bool> $days */
        $days = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            if ((int) $local->format('n') !== $mon) {
                continue;
            }
            $picked[] = $m;
            $years[(int) $local->format('Y')] = true;
            $days[$local->format('Y-m-d')]    = true;
        }

        if (\count($picked) < $this->minItems || \count($years) < $this->minYears || \count($days) < $this->minDistinctDays) {
            return [];
        }

        return $this->buildOverYearsDrafts(
            $picked,
            $years,
            $this->minYears,
            $this->minItems,
            $this->name(),
            ['month' => $mon]
        );
    }
}
