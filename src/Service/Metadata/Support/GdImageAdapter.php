<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use GdImage;
use Throwable;

use function file_get_contents;
use function imagecolorat;
use function imagecopyresampled;
use function imagecreatefromgif;
use function imagecreatefromjpeg;
use function imagecreatefrompng;
use function imagecreatefromstring;
use function imagecreatefromwebp;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagesx;
use function imagesy;
use function is_file;
use function is_string;
use function pathinfo;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * GD-based adapter.
 */
final readonly class GdImageAdapter implements ImageAdapterInterface
{
    public function __construct(
        private GdImage $image,
    ) {
    }

    public static function fromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $im  = null;

        try {
            $im = match ($ext) {
                'jpg', 'jpeg', 'jpe' => @imagecreatefromjpeg($path),
                'png'   => @imagecreatefrompng($path),
                'webp'  => @imagecreatefromwebp($path),
                'gif'   => @imagecreatefromgif($path),
                default => null,
            };

            if (!$im instanceof GdImage) {
                // Fallback: sniff via blob (helps for uncommon extensions)
                $blob = @file_get_contents($path);
                if (is_string($blob) && $blob !== '') {
                    $created = @imagecreatefromstring($blob);
                    if ($created instanceof GdImage) {
                        $im = $created;
                    }
                }
            }
        } catch (Throwable) {
            $im = null;
        }

        return $im instanceof GdImage ? new self($im) : null;
    }

    public function getWidth(): int
    {
        return imagesx($this->image);
    }

    public function getHeight(): int
    {
        return imagesy($this->image);
    }

    public function getLuma(int $x, int $y): float
    {
        $rgb = imagecolorat($this->image, $x, $y);
        $r   = ($rgb >> 16) & 0xFF;
        $g   = ($rgb >> 8) & 0xFF;
        $b   = $rgb & 0xFF;

        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    public function resize(int $targetWidth, int $targetHeight): ImageAdapterInterface
    {
        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled(
            $dst,
            $this->image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($this->image),
            imagesy($this->image)
        );

        return new self($dst);
    }

    public function destroy(): void
    {
        imagedestroy($this->image);
    }

    public function getNative(): GdImage
    {
        return $this->image;
    }
}
