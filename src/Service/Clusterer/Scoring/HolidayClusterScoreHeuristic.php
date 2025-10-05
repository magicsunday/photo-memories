<?php 


/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;

/**
 * Class HolidayClusterScoreHeuristic
 */
final class HolidayClusterScoreHeuristic extends AbstractTimeRangeClusterScoreHeuristic
{
    public function __construct(
        private HolidayResolverInterface $holidayResolver,
        int $timeRangeMinSamples,
        float $timeRangeMinCoverage,
        int $minValidYear,
    ) {
        parent::__construct($timeRangeMinSamples, $timeRangeMinCoverage, $minValidYear);
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params    = $cluster->getParams();
        $timeRange = $this->ensureTimeRange($cluster, $mediaMap);
        $holiday   = $this->floatOrNull($params['holiday'] ?? null) ?? 0.0;

        if ($timeRange !== null && !isset($params['holiday'])) {
            $holiday = $this->computeHolidayScore((int) $timeRange['from'], (int) $timeRange['to']);
        }

        if ($timeRange !== null || isset($params['holiday'])) {
            $cluster->setParam('holiday', $holiday);
        }
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['holiday'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'holiday';
    }

    private function computeHolidayScore(int $fromTs, int $toTs): float
    {
        if ($toTs < $fromTs) {
            return 0.0;
        }

        $start = (new DateTimeImmutable('@' . $fromTs))->setTime(0, 0);
        $end   = (new DateTimeImmutable('@' . $toTs))->setTime(0, 0);

        $onHoliday = false;
        $onWeekend = false;

        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            if ($this->holidayResolver->isHoliday($d)) {
                $onHoliday = true;
                break;
            }

            $dow = (int) $d->format('N');
            if ($dow >= 6) {
                $onWeekend = true;
            }
        }

        if ($onHoliday) {
            return 1.0;
        }

        if ($onWeekend) {
            return 0.5;
        }

        return 0.0;
    }
}
