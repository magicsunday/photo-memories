<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use MagicSunday\Memories\Entity\Media;

use function array_values;
use function bindec;
use function dechex;
use function is_array;
use function is_file;
use function is_string;
use function sort;
use function str_repeat;
use function strlen;
use function substr;

use const SORT_STRING;

/**
 * Shared image helpers for GD/Imagick backends.
 */
trait GdImageToolsTrait
{
    /**
     * Prefer smallest available thumbnail; fall back to original path.
     */
    private function resolveImageSource(Media $m): ?string
    {
        $thumbs = $m->getThumbnails();

        if (is_array($thumbs) && $thumbs !== []) {
            $paths = array_values($thumbs);
            sort($paths, SORT_STRING);
            $p = $paths[0];
            if (is_string($p) && is_file($p)) {
                return $p;
            }
        }

        $p = $m->getPath();

        return is_file($p) ? $p : null;
    }

    /**
     * Preferred loader: returns a backend adapter (Imagick first, then GD).
     */
    private function createImageAdapter(string $path): ?ImageAdapterInterface
    {
        // Try Imagick first (supports HEIC/AVIF if delegates installed)
        $imgk = ImagickImageAdapter::fromFile($path);
        if ($imgk instanceof ImageAdapterInterface) {
            return $imgk;
        }

        // Fallback to GD
        $gd = GdImageAdapter::fromFile($path);
        if ($gd instanceof ImageAdapterInterface) {
            return $gd;
        }

        return null;
    }

    /**
     * Convert bitstring to hex with safe padding.
     *
     * @param string   $bits       bit string like "1010..."
     * @param int|null $targetBits if provided, left-pad to this bit length first
     */
    private function bitsToHex(string $bits, ?int $targetBits = null): string
    {
        $len = strlen($bits);

        if ($targetBits !== null && $targetBits > $len) {
            $bits = str_repeat('0', $targetBits - $len) . $bits;
            $len  = $targetBits;
        }

        // pad to nibble boundary
        $padBits = (4 - ($len % 4)) % 4;
        if ($padBits > 0) {
            $bits = str_repeat('0', $padBits) . $bits;
            $len += $padBits;
        }

        $hex = '';
        for ($i = 0; $i < $len; $i += 4) {
            $chunk = substr($bits, $i, 4);
            $hex .= dechex(bindec($chunk));
        }

        return $hex;
    }

    /**
     * Build a grayscale luma matrix by first resizing to (w×h).
     *
     * @return array<int, array<int, float>> Matrix [y][x] with luma [0..255]
     */
    private function grayscaleMatrixFromAdapter(ImageAdapterInterface $src, int $w, int $h): array
    {
        // Imagick Fast-Path: ein Export statt 1024 getLuma()-Aufrufe
        if ($src instanceof ImagickImageAdapter) {
            $rgb = $src->exportRgbBytes($w, $h); // length = w*h*3
            $out = [];
            $idx = 0;
            for ($y = 0; $y < $h; ++$y) {
                $row = [];
                for ($x = 0; $x < $w; ++$x) {
                    $r     = (float) $rgb[$idx++]; // 0..255
                    $g     = (float) $rgb[$idx++];
                    $b     = (float) $rgb[$idx++];
                    $row[] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                }

                $out[] = $row;
            }

            return $out;
        }

        // Generischer Fallback: wie bisher (GD o. ä.)
        $resized = $src->resize($w, $h);
        $out     = [];
        for ($y = 0; $y < $h; ++$y) {
            $row = [];
            for ($x = 0; $x < $w; ++$x) {
                $row[] = $resized->getLuma($x, $y);
            }

            $out[] = $row;
        }

        $resized->destroy();

        return $out;
    }
}
