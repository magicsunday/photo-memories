<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Support;

use MagicSunday\Memories\Entity\Media;

use function str_ends_with;

/**
 * Utility helpers for working with the media index log.
 */
final class IndexLogHelper
{
    private function __construct()
    {
    }

    /**
     * Appends a line to the media index log, inserting a newline when required.
     *
     * @param Media  $media Media entity whose index log should be updated.
     * @param string $line  Line that should be appended to the log.
     */
    public static function append(Media $media, string $line): void
    {
        if ($line === '') {
            return;
        }

        $existing = $media->getIndexLog();
        if ($existing === null || $existing === '') {
            $media->setIndexLog($line);

            return;
        }

        if (str_ends_with($existing, "\n")) {
            $media->setIndexLog($existing . $line);

            return;
        }

        $media->setIndexLog($existing . "\n" . $line);
    }
}

