<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function is_bool;
use function is_string;
use function preg_match;
use function strtolower;

use const PHP_INT_MAX;

/**
 * Small helper to build ClusterDraft fields from Media lists.
 */
trait ClusterBuildHelperTrait
{
    /**
     * @param list<Media> $members
     *
     * @return array{lat: float, lon: float}
     */
    private function computeCentroid(array $members): array
    {
        return MediaMath::centroid($members);
    }

    /**
     * @param list<Media> $members
     *
     * @return list<int>
     */
    private function toMemberIds(array $members): array
    {
        $out = [];
        foreach ($members as $m) {
            $out[] = $m->getId();
        }

        return $out;
    }

    /**
     * @param list<Media> $members
     *
     * @return array{from:int,to:int}
     */
    private function computeTimeRange(array $members): array
    {
        $from = PHP_INT_MAX;
        $to   = 0;

        foreach ($members as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts !== null) {
                if ($ts < $from) {
                    $from = $ts;
                }

                if ($ts > $to) {
                    $to = $ts;
                }
            }
        }

        if ($from === PHP_INT_MAX) {
            $from = 0;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Extracts calendar related features from the media entity.
     *
     * @return array{season: ?string, isWeekend: ?bool, isHoliday: ?bool, holidayId: ?string}
     */
    private function extractCalendarFeatures(Media $media): array
    {
        $features = $media->getFeatures();

        $season = null;
        $isWeekend = null;
        $isHoliday = null;
        $holidayId = null;

        if ($features !== null) {
            $seasonValue = $features['season'] ?? null;
            if (is_string($seasonValue) && $seasonValue !== '') {
                $season = strtolower($seasonValue);
            }

            $weekendValue = $features['isWeekend'] ?? null;
            if (is_bool($weekendValue)) {
                $isWeekend = $weekendValue;
            }

            $holidayValue = $features['isHoliday'] ?? null;
            if (is_bool($holidayValue)) {
                $isHoliday = $holidayValue;
            }

            $holidayIdValue = $features['holidayId'] ?? null;
            if (is_string($holidayIdValue) && $holidayIdValue !== '') {
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
     * Maps the seasonal feature slug to the localized label used by cluster strategies.
     */
    private function seasonLabelFromFeature(?string $season): ?string
    {
        if ($season === null) {
            return null;
        }

        return match ($season) {
            'winter' => 'Winter',
            'spring' => 'Frühling',
            'summer' => 'Sommer',
            'autumn' => 'Herbst',
            default  => null,
        };
    }

    /**
     * @return array{code: ?string, year: ?int}
     */
    private function decodeHolidayFeature(?string $holidayId): array
    {
        if ($holidayId === null) {
            return ['code' => null, 'year' => null];
        }

        if (preg_match('/^(.+)-(\d{4,})$/', $holidayId, $matches) !== 1) {
            return ['code' => null, 'year' => null];
        }

        return [
            'code' => $matches[1],
            'year' => (int) $matches[2],
        ];
    }

    /**
     * Resolves the human readable holiday label for the encoded calendar feature identifier.
     */
    private function holidayLabelFromCode(string $code): ?string
    {
        return match ($code) {
            'de-newyear'    => 'Neujahr',
            'de-labour'     => 'Tag der Arbeit',
            'de-unity'      => 'Tag der Deutschen Einheit',
            'de-xmas1'      => '1. Weihnachtstag',
            'de-xmas2'      => '2. Weihnachtstag',
            'de-goodfriday' => 'Karfreitag',
            'de-eastermon'  => 'Ostermontag',
            'de-ascension'  => 'Christi Himmelfahrt',
            'de-whitmonday' => 'Pfingstmontag',
            default         => null,
        };
    }
}
