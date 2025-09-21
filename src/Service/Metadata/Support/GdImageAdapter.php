<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use GdImage;

/**
 * GD-based adapter.
 */
final class GdImageAdapter implements ImageAdapterInterface
{
    public function __construct(
        private readonly GdImage $image
    ) {
    }

    public static function fromFile(string $path): ?self
    {
        if (!\is_file($path)) {
            return null;
        }
        $ext = \strtolower((string) \pathinfo($path, \PATHINFO_EXTENSION));
        $im = null;

        try {
            $im = match ($ext) {
                'jpg', 'jpeg', 'jpe' => @\imagecreatefromjpeg($path),
                'png'                 => @\imagecreatefrompng($path),
                'webp'                => @\imagecreatefromwebp($path),
                'gif'                 => @\imagecreatefromgif($path),
                default               => null,
            };

            if (!$im instanceof GdImage) {
                // Fallback: sniff via blob (helps for uncommon extensions)
                $blob = @\file_get_contents($path);
                if (\is_string($blob) && $blob !== '') {
                    $im = @\imagecreatefromstring($blob) ?: null;
                }
            }
        } catch (\Throwable) {
            $im = null;
        }

        return $im instanceof GdImage ? new self($im) : null;
    }

    public function getWidth(): int
    {
        return \imagesx($this->image);
    }

    public function getHeight(): int
    {
        return \imagesy($this->image);
    }

    public function getLuma(int $x, int $y): float
    {
        $rgb = \imagecolorat($this->image, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    public function resize(int $targetWidth, int $targetHeight): ImageAdapterInterface
    {
        $dst = \imagecreatetruecolor($targetWidth, $targetHeight);
        \imagecopyresampled(
            $dst,
            $this->image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            \imagesx($this->image),
            \imagesy($this->image)
        );

        return new self($dst);
    }

    public function destroy(): void
    {
        \imagedestroy($this->image);
    }

    public function getNative(): GdImage
    {
        return $this->image;
    }
}
