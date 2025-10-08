<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Entity\Media;

use function array_key_first;
use function count;
use function is_string;
use function trim;

/**
 * Helper to access calendar related media features in a consistent way.
 */
final class CalendarFeatureHelper
{
    private function __construct()
    {
    }

    /**
     * Extracts selected calendar features from the media entity.
     *
     * @return array{
     *     season: ?string,
     *     isWeekend: ?bool,
     *     isHoliday: ?bool,
     *     holidayId: ?string
     * }
     */
    public static function extract(Media $media): array
    {
        $season    = null;
        $isWeekend = null;
        $isHoliday = null;
        $holidayId = null;

        $bag         = $media->getFeatureBag();
        $seasonValue = $bag->calendarSeason();
        if ($seasonValue !== null) {
            $seasonValue = trim($seasonValue);
            if ($seasonValue !== '') {
                $season = $seasonValue;
            }
        }

        $isWeekendValue = $bag->calendarIsWeekend();
        if ($isWeekendValue !== null) {
            $isWeekend = $isWeekendValue;
        }

        $isHolidayValue = $bag->calendarIsHoliday();
        if ($isHolidayValue !== null) {
            $isHoliday = $isHolidayValue;
        }

        $holidayIdValue = $bag->calendarHolidayId();
        if ($holidayIdValue !== null) {
            $holidayIdValue = trim($holidayIdValue);
            if ($holidayIdValue !== '') {
                $holidayId = $holidayIdValue;
            }
        }

        return [
            'season'    => $season,
            'isWeekend' => $isWeekend,
            'isHoliday' => $isHoliday,
            'holidayId' => $holidayId,
        ];
    }

    /**
     * Aggregates the calendar flags for a list of media entries.
     *
     * @param list<Media> $items
     *
     * @return array{isWeekend: ?bool, holidayId: ?string}
     */
    public static function summarize(array $items): array
    {
        $weekendTrue  = 0;
        $weekendFalse = 0;
        $holidayIds   = [];

        foreach ($items as $media) {
            $features  = self::extract($media);
            $isWeekend = $features['isWeekend'];
            if ($isWeekend === true) {
                ++$weekendTrue;
            } elseif ($isWeekend === false) {
                ++$weekendFalse;
            }

            $holidayId = $features['holidayId'];
            if ($holidayId !== null) {
                $holidayIds[$holidayId] = true;
            }
        }

        $isWeekend = null;
        if ($weekendTrue > 0 && $weekendFalse === 0) {
            $isWeekend = true;
        } elseif ($weekendFalse > 0 && $weekendTrue === 0) {
            $isWeekend = false;
        }

        $holidayId = null;
        if (count($holidayIds) === 1) {
            $first = array_key_first($holidayIds);
            if (is_string($first)) {
                $holidayId = $first;
            }
        }

        return [
            'isWeekend' => $isWeekend,
            'holidayId' => $holidayId,
        ];
    }
}
