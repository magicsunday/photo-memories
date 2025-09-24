<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;

/**
 * Helper for translating a date into a German season label and canonical year.
 */
trait SeasonHelperTrait
{
    /**
     * @return array{season:string, seasonYear:int}
     */
    private function seasonInfo(DateTimeImmutable $date): array
    {
        $month = (int) $date->format('n');
        $year = (int) $date->format('Y');

        $season = match (true) {
            $month >= 3 && $month <= 5  => 'Frühling',
            $month >= 6 && $month <= 8  => 'Sommer',
            $month >= 9 && $month <= 11 => 'Herbst',
            default => 'Winter',
        };

        if ($season === 'Winter' && $month === 12) {
            $year++;
        }

        return ['season' => $season, 'seasonYear' => $year];
    }
}
