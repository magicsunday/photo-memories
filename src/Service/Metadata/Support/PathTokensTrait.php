<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use function preg_split;
use function str_replace;
use function strlen;
use function strtolower;

/**
 * Trait PathTokensTrait.
 */
trait PathTokensTrait
{
    /** @return list<string> */
    private function tokenizePath(string $path): array
    {
        $lower  = strtolower($path);
        $delims = ['/', '\\', '-', '_', '.', ' '];
        $tmp    = str_replace($delims, ' ', $lower);
        $parts  = preg_split('~\s+~', $tmp) ?: [];
        /** @var list<string> $out */
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '' && strlen($p) <= 64) {
                $out[] = $p;
            }
        }

        return $out;
    }
}
