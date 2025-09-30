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
use DateTimeZone;
use Exception;
use MagicSunday\Memories\Entity\Media;

use function sprintf;

final class DaypartEnricher implements SingleMetadataExtractorInterface
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

        $local = $this->withOffsetTimezone($t, $media->getTimezoneOffsetMin());
        $h     = (int) $local->format('G');

        $part = match (true) {
            $h >= 6 && $h <= 10  => 'morning',
            $h >= 11 && $h <= 16 => 'noon',
            $h >= 17 && $h <= 21 => 'evening',
            default              => 'night',
        };

        $features            = $media->getFeatures() ?? [];
        $features['daypart'] = $part;
        $media->setFeatures($features);

        return $media;
    }

    private function withOffsetTimezone(DateTimeImmutable $instant, ?int $offsetMinutes): DateTimeImmutable
    {
        if ($offsetMinutes === null) {
            return $instant;
        }

        $sign      = $offsetMinutes >= 0 ? '+' : '-';
        $abs       = $offsetMinutes < 0 ? -$offsetMinutes : $offsetMinutes;
        $hours     = intdiv($abs, 60);
        $minutes   = $abs % 60;
        $tzSpec    = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

        try {
            $tz = new DateTimeZone($tzSpec);
        } catch (Exception) {
            return $instant;
        }

        return $instant->setTimezone($tz);
    }
}
