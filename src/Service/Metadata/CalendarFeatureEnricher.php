<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;

use function gmdate;
use function is_int;
use function sprintf;
use function strtotime;

/**
 * Adds weekend/season/holiday flags derived from takenAt.
 */
final class CalendarFeatureEnricher implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return $media->getTakenAt() instanceof DateTimeImmutable;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $t = $media->getTakenAt();
        if (!$t instanceof DateTimeImmutable) {
            return $media;
        }

        $y   = (int) $t->format('Y');
        $m   = (int) $t->format('n');
        $d   = (int) $t->format('j');
        $dow = (int) $t->format('N');

        $features              = $media->getFeatures() ?? [];
        $features['dow']       = $dow;
        $features['isWeekend'] = ($dow >= 6);
        $features['season']    = match ($m) {
            12, 1, 2 => 'winter',
            3, 4, 5 => 'spring',
            6, 7, 8 => 'summer',
            default => 'autumn',
        };

        [$isHoliday, $id]      = $this->isGermanHoliday($y, $m, $d);
        $features['isHoliday'] = $isHoliday;
        if ($id !== null) {
            $features['holidayId'] = $id;
        }

        $media->setFeatures($features);

        return $media;
    }

    /** @return array{bool, ?string} */
    private function isGermanHoliday(int $y, int $m, int $d): array
    {
        $md    = sprintf('%02d-%02d', $m, $d);
        $fixed = [
            '01-01' => 'de-newyear',
            '05-01' => 'de-labour',
            '10-03' => 'de-unity',
            '12-25' => 'de-xmas1',
            '12-26' => 'de-xmas2',
        ];
        if (isset($fixed[$md])) {
            return [true, $fixed[$md] . '-' . $y];
        }

        $easter = $this->easterDate($y);
        $tsE    = strtotime($easter);
        if (!is_int($tsE)) {
            return [false, null];
        }

        $rel = [
            -2  => 'de-goodfriday',
            +1  => 'de-eastermon',
            +39 => 'de-ascension',
            +50 => 'de-whitmonday',
        ];
        foreach ($rel as $off => $code) {
            $ts  = $tsE + $off * 86400;
            $ymd = gmdate('Y-m-d', $ts);
            if ($ymd === sprintf('%04d-%02d-%02d', $y, $m, $d)) {
                return [true, $code . '-' . $y];
            }
        }

        return [false, null];
    }

    private function easterDate(int $y): string
    {
        $a     = $y % 19;
        $b     = intdiv($y, 100);
        $c     = $y % 100;
        $d     = intdiv($b, 4);
        $e     = $b % 4;
        $f     = intdiv($b + 8, 25);
        $g     = intdiv($b - $f + 1, 3);
        $h     = (19 * $a + $b - $d - $g + 15) % 30;
        $i     = intdiv($c, 4);
        $k     = $c % 4;
        $l     = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m     = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $y, $month, $day);
    }
}
