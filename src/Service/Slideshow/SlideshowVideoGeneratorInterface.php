<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

/**
 * Generates slideshow videos for feed cards.
 */
interface SlideshowVideoGeneratorInterface
{
    public function generate(SlideshowJob $job): void;
}
