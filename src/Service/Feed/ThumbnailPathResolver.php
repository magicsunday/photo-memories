<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use MagicSunday\Memories\Entity\Media;

/**
 * Resolves a suitable thumbnail path for a media, with fallback to original file path.
 */
final class ThumbnailPathResolver
{
    public function resolveBest(Media $m, int $desiredWidth): ?string
    {
        $thumbs = $m->getThumbnails();

        // Prefer associative [size => path]
        if (\is_array($thumbs) && $thumbs !== []) {
            /** @var array<int,string> $bySize */
            $bySize = [];
            foreach ($thumbs as $k => $v) {
                if (\is_string($k) && \is_string($v) && \is_numeric($k)) {
                    $bySize[(int) $k] = $v;
                } elseif (\is_int($k) && \is_string($v)) {
                    $bySize[$k] = $v;
                }
            }

            if ($bySize !== []) {
                \ksort($bySize, \SORT_NUMERIC);
                $best = null;
                foreach ($bySize as $w => $p) {
                    if ($w <= $desiredWidth) {
                        $best = $p;
                    } else {
                        break;
                    }
                }

                if (\is_string($best) && \is_file($best)) {
                    return $best;
                }

                // fallback to the smallest available
                $first = \reset($bySize);
                if (\is_string($first) && \is_file($first)) {
                    return $first;
                }
            }

            // Or treat as list of paths
            $values = \array_values($thumbs);
            \sort($values, \SORT_STRING);
            foreach ($values as $p) {
                if (\is_string($p) && \is_file($p)) {
                    return $p;
                }
            }
        }

        // Fallback to original file
        $orig = $m->getPath();
        return \is_file($orig) ? $orig : null;
    }

    public function exportName(Media $m, string $srcPath): string
    {
        $id = $m->getId() ?? 0;
        $base = \basename($srcPath);
        return 'm'.$id.'_'.$base;
    }
}
