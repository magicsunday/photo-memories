<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Support;

use GdImage;

use function imagecolorallocate;
use function imagecolorallocatealpha;

/**
 * Allocates GD palette entries for image fixtures and fails the test when GD refuses.
 */
trait GdColorAllocationTrait
{
    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     */
    private static function allocateGdColor(GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate($image, $red, $green, $blue);

        if ($color === false) {
            self::fail('Unable to allocate GD color.');
        }

        return $color;
    }

    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     * @param int<0, 127> $alpha
     */
    private static function allocateGdColorAlpha(GdImage $image, int $red, int $green, int $blue, int $alpha): int
    {
        $color = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);

        if ($color === false) {
            self::fail('Unable to allocate GD color.');
        }

        return $color;
    }
}
