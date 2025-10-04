<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use Imagick;
use Throwable;

use function class_exists;
use function count;
use function extension_loaded;
use function is_file;
use function round;

/**
 * Imagick-based adapter, supports HEIC/AVIF (depends on delegates).
 */
final readonly class ImagickImageAdapter implements ImageAdapterInterface
{
    public function __construct(
        private Imagick $image,
    ) {
    }

    public static function fromFile(string $path): ?self
    {
        if (!class_exists(Imagick::class) || !extension_loaded('imagick')) {
            return null;
        }

        if (!is_file($path)) {
            return null;
        }

        try {
            $im = new Imagick($path);

            // Auto-orient according to EXIF
            try {
                $im->autoOrient();
            } catch (Throwable) {
                try {
                    /** @phpstan-ignore-next-line */
                    $im->autoOrientate();
                } catch (Throwable) {
                    // Ignore when neither orientation helper is available.
                }
            }

            // Normalize to sRGB and 8-bit for stable luma
            try {
                $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            } catch (Throwable) {
                // Fallback gracefully when color space normalization is unavailable.
            }

            try {
                $im->setImageDepth(8);
            } catch (Throwable) {
                // Older Imagick builds may not allow adjusting the bit depth.
            }

            return new self($im);
        } catch (Throwable) {
            return null;
        }
    }

    public function getWidth(): int
    {
        return $this->image->getImageWidth();
    }

    public function getHeight(): int
    {
        return $this->image->getImageHeight();
    }

    public function getLuma(int $x, int $y): float
    {
        /** @var array{r:float,g:float,b:float} $c */
        $c = $this->image->getImagePixelColor($x, $y)->getColor(1); // normalized [0..1]
        $r = $c['r'] * 255.0;
        $g = $c['g'] * 255.0;
        $b = $c['b'] * 255.0;

        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    public function resize(int $targetWidth, int $targetHeight): ImageAdapterInterface
    {
        $clone = clone $this->image;
        $clone->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1.0, true);

        return new self($clone);
    }

    public function destroy(): void
    {
        $this->image->clear();
        $this->image->destroy();
    }

    public function getNative(): Imagick
    {
        return $this->image;
    }

    /**
     * Export RGB bytes of a resized clone (w × h).
     *
     * @return list<int> Flat array [R,G,B, R,G,B, ...] in 0..255, length = w*h*3
     */
    public function exportRgbBytes(int $w, int $h): array
    {
        $clone = clone $this->image;

        // robust, schnell, geringere Speicherlast
        $clone->setIteratorIndex(0);
        $clone->setImageColorspace(Imagick::COLORSPACE_RGB);
        $clone->transformImageColorspace(Imagick::COLORSPACE_RGB);
        $clone->thumbnailImage($w, $h, true, true); // bestfit + crop

        /** @var array<int,int|float> $buf */
        $buf = $clone->exportImagePixels(
            0,
            0,
            $clone->getImageWidth(),
            $clone->getImageHeight(),
            'RGB',
            Imagick::PIXEL_CHAR
        );

        $clone->destroy();

        // normalisieren auf ints 0..255
        $out    = [];
        $outLen = count($buf);

        for ($i = 0; $i < $outLen; ++$i) {
            $v     = $buf[$i];
            $out[] = (int) (is_float($v) ? round($v) : $v);
        }

        /** @var list<int> $out */
        return $out;
    }
}
