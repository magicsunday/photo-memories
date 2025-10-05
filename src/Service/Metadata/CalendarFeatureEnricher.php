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
use MagicSunday\Memories\Utility\Calendar;

use function sprintf;

/**
 * Adds weekend/season/holiday flags derived from takenAt.
 */
final class CalendarFeatureEnricher implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return $media->getTakenAt() instanceof DateTimeImmutable;
    }

    /**
     * Enriches the media entity with seasonal and holiday features based on the capture date.
     */
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

    /**
     * Determines whether the provided date matches a nationally recognised German holiday.
     *
     * @param int $y
     * @param int $m
     * @param int $d
     *
     * @return array{bool, ?string}
     * @throws \DateMalformedStringException
     */
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

        // Easter Sunday is calculated via the shared calendar helper to avoid duplicated algorithms.
        $origin = Calendar::easterSunday($y);

        $rel = [
            // Relative offsets (in days) from Easter Sunday for moveable feasts in Germany.
            -2  => 'de-goodfriday',
            +1  => 'de-eastermon',
            +39 => 'de-ascension',
            +50 => 'de-whitmonday',
        ];
        foreach ($rel as $off => $code) {
            $target = $origin->modify(sprintf('%+d day', $off));
            if (!$target instanceof DateTimeImmutable) {
                continue;
            }

            $ymd = $target->format('Y-m-d');
            if ($ymd === sprintf('%04d-%02d-%02d', $y, $m, $d)) {
                return [true, $code . '-' . $y];
            }
        }

        return [false, null];
    }
}
