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

use function is_array;
use function is_bool;
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
        $features = $media->getFeatures();

        $season    = null;
        $isWeekend = null;
        $isHoliday = null;
        $holidayId = null;

        if (is_array($features)) {
            $seasonValue = $features['season'] ?? null;
            if (is_string($seasonValue)) {
                $seasonValue = trim($seasonValue);
                if ($seasonValue !== '') {
                    $season = $seasonValue;
                }
            }

            $isWeekendValue = $features['isWeekend'] ?? null;
            if (is_bool($isWeekendValue)) {
                $isWeekend = $isWeekendValue;
            }

            $isHolidayValue = $features['isHoliday'] ?? null;
            if (is_bool($isHolidayValue)) {
                $isHoliday = $isHolidayValue;
            }

            $holidayIdValue = $features['holidayId'] ?? null;
            if (is_string($holidayIdValue)) {
                $holidayIdValue = trim($holidayIdValue);
                if ($holidayIdValue !== '') {
                    $holidayId = $holidayIdValue;
                }
            }
        }

        return [
            'season'    => $season,
            'isWeekend' => $isWeekend,
            'isHoliday' => $isHoliday,
            'holidayId' => $holidayId,
        ];
    }
}
