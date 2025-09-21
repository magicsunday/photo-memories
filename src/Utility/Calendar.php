<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use DateInterval;
use DateTimeImmutable;

/**
 * Calendar helpers (Gregorian).
 */
final class Calendar
{
    private function __construct() {}

    /**
     * Western Easter Sunday (Anonymous Gregorian algorithm).
     */
    public static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); // 3=March, 4=April
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(\sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * Returns the German federal holiday name for a given day, or null if none.
     * (No state-specific holidays.)
     */
    public static function germanFederalHolidayName(DateTimeImmutable $day): ?string
    {
        $y = (int) $day->format('Y');
        $key = $day->format('Y-m-d');

        $fixed = [
            "{$y}-01-01" => 'Neujahr',
            "{$y}-05-01" => 'Tag der Arbeit',
            "{$y}-10-03" => 'Tag der Deutschen Einheit',
            "{$y}-12-25" => '1. Weihnachtstag',
            "{$y}-12-26" => '2. Weihnachtstag',
        ];
        if (isset($fixed[$key])) {
            return $fixed[$key];
        }

        $easter = self::easterSunday($y);
        $goodFriday   = $easter->sub(new DateInterval('P2D'));
        $easterMonday = $easter->add(new DateInterval('P1D'));
        $ascension    = $easter->add(new DateInterval('P39D'));
        $whitMonday   = $easter->add(new DateInterval('P50D'));

        $movables = [
            $goodFriday->format('Y-m-d')   => 'Karfreitag',
            $easterMonday->format('Y-m-d') => 'Ostermontag',
            $ascension->format('Y-m-d')    => 'Christi Himmelfahrt',
            $whitMonday->format('Y-m-d')   => 'Pfingstmontag',
        ];

        return $movables[$key] ?? null;
    }
}
