<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use MagicSunday\Memories\Entity\Media;

use function basename;
use function ctype_digit;
use function is_array;
use function is_file;
use function is_string;
use function ksort;
use function reset;

use const SORT_NUMERIC;

/**
 * Resolves a suitable thumbnail path for a media, with fallback to original file path.
 */
final class ThumbnailPathResolver
{
    public function resolveBest(Media $m, int $desiredWidth): ?string
    {
        $thumbs = $m->getThumbnails();

        if (!is_array($thumbs) || $thumbs === []) {
            return $this->resolveOriginal($m);
        }

        /** @var array<int, string> $bySize */
        $bySize = [];
        $fallbackPaths = [];

        foreach ($thumbs as $key => $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (is_int($key)) {
                $bySize[$key] = $path;

                continue;
            }

            if (is_string($key) && ctype_digit($key)) {
                $bySize[(int) $key] = $path;

                continue;
            }

            $fallbackPaths[] = $path;
        }

        if ($bySize !== []) {
            ksort($bySize, SORT_NUMERIC);

            $best = null;
            foreach ($bySize as $width => $candidate) {
                if ($width > $desiredWidth) {
                    break;
                }

                if (is_file($candidate)) {
                    $best = $candidate;
                }
            }

            if ($best !== null) {
                return $best;
            }

            $first = reset($bySize);
            if (is_string($first) && is_file($first)) {
                return $first;
            }

            foreach ($bySize as $candidate) {
                $fallbackPaths[] = $candidate;
            }
        }

        foreach ($fallbackPaths as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $this->resolveOriginal($m);
    }

    public function exportName(Media $m, string $srcPath): string
    {
        $id   = $m->getId() ?? 0;
        $base = basename($srcPath);

        return 'm' . $id . '_' . $base;
    }

    private function resolveOriginal(Media $media): ?string
    {
        $path = $media->getPath();

        return is_file($path) ? $path : null;
    }
}
