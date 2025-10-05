<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;

use function abs;
use function intdiv;
use function is_int;
use function sprintf;

/**
 * Resolves the best local capture time for media items.
 */
final readonly class LocalTimeHelper
{
    private DateTimeZone $fallbackTimezone;

    public function __construct(string $fallbackTimezone)
    {
        $this->fallbackTimezone = new DateTimeZone($fallbackTimezone);
    }

    public function resolve(Media $media): ?DateTimeImmutable
    {
        $capturedLocal = $media->getCapturedLocal();
        if ($capturedLocal instanceof DateTimeImmutable) {
            return $capturedLocal;
        }

        $takenAt = $media->getTakenAt();
        if (!($takenAt instanceof DateTimeImmutable)) {
            return null;
        }

        $offsetMinutes = $media->getTimezoneOffsetMin();
        if (is_int($offsetMinutes)) {
            return $takenAt->setTimezone($this->createOffsetTimezone($offsetMinutes));
        }

        return $takenAt->setTimezone($this->fallbackTimezone);
    }

    private function createOffsetTimezone(int $offsetMinutes): DateTimeZone
    {
        $sign           = $offsetMinutes >= 0 ? '+' : '-';
        $absolute       = abs($offsetMinutes);
        $hours          = intdiv($absolute, 60);
        $minutes        = $absolute % 60;
        $formattedZone  = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

        return new DateTimeZone($formattedZone);
    }
}
