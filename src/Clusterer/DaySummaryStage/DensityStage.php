<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;

use function array_sum;
use function count;
use function sqrt;

/**
 * Calculates density z-scores for day summaries.
 */
final class DensityStage implements DaySummaryStageInterface
{
    private const float MIN_STD_EPSILON = 1.0e-6;

    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        $photoCounts = [];
        foreach ($days as $summary) {
            $photoCounts[] = $summary['photoCount'];
        }

        $stats = $this->computeMeanStd($photoCounts);
        foreach ($days as &$summary) {
            if ($stats['std'] > self::MIN_STD_EPSILON) {
                $summary['densityZ'] = ($summary['photoCount'] - $stats['mean']) / $stats['std'];
            } else {
                $summary['densityZ'] = 0.0;
            }
        }

        unset($summary);

        return $days;
    }

    /**
     * @param list<int> $values
     *
     * @return array{mean: float, std: float}
     */
    private function computeMeanStd(array $values): array
    {
        $count = count($values);
        if ($count === 0) {
            return ['mean' => 0.0, 'std' => 0.0];
        }

        $sum  = array_sum($values);
        $mean = $sum / $count;

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        $std = sqrt($variance / $count);

        return ['mean' => $mean, 'std' => $std];
    }
}
