<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Thumbnail;

use GdImage;
use Imagick;
use MagicSunday\Memories\Entity\Media;
use RuntimeException;

/**
 * Thumbnail service that supports GD and Imagick fallback.
 */
class ThumbnailService implements ThumbnailServiceInterface
{
    private readonly string $thumbnailDir;

    /** @var int[] sizes in px (width) */
    private readonly array $sizes;

    public function __construct(string $thumbnailDir, array $sizes = [320, 1024])
    {
        $this->thumbnailDir = $thumbnailDir;
        $this->sizes        = $sizes;
        if (!is_dir($this->thumbnailDir)) {
            @mkdir($this->thumbnailDir, 0755, true);
        }
    }

    /**
     * Generate thumbnails for a media file.
     *
     * @return array map size => path
     */
    public function generateAll(string $filepath, Media $media): array
    {
        $results = [];
        foreach ($this->sizes as $size) {
            $path = $this->generateThumbnail($filepath, $size, $media);
            if ($path !== null) {
                $results[$size] = $path;
            }
        }

        return $results;
    }

    private function generateThumbnail(string $filepath, int $width, Media $media): ?string
    {
        $orientation = $media->getOrientation();

        if (extension_loaded('imagick')) {
            return $this->generateWithImagick($filepath, $width, $orientation);
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->generateWithGd($filepath, $width, $orientation);
        }

        throw new RuntimeException('No available image library (Imagick or GD) to create thumbnails');
    }

    private function generateWithImagick(string $filepath, int $width, ?int $orientation): ?string
    {
        $im = new Imagick();
        $im->setOption('jpeg:preserve-settings', 'true');
        $im->readImage($filepath . '[0]');
        if ($orientation !== null && $orientation >= 1 && $orientation <= 8) {
            $im->setImageOrientation($orientation);
        }
        $im->autoOrientImage();
        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        $im->thumbnailImage($width, 0);

        $hash = hash('crc32b', $filepath . ':' . $width);
        $out  = $this->thumbnailDir . DIRECTORY_SEPARATOR . $hash . '.jpg';
        if ($im->writeImage($out)) {
            $im->clear();
            $im->destroy();

            return $out;
        }

        $im->clear();
        $im->destroy();

        return null;
    }

    private function generateWithGd(string $filepath, int $width, ?int $orientation): ?string
    {
        $data = @file_get_contents($filepath);
        if ($data === false) {
            return null;
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }

        $src = $this->applyOrientationWithGd($src, $orientation);

        $w     = imagesx($src);
        $h     = imagesy($src);
        $ratio = $h > 0 ? ($w / $h) : 1;
        $newW  = $width;
        $newH  = (int) round($width / $ratio);
        $dst   = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        $hash = hash('crc32b', $filepath . ':' . $width);
        $out  = $this->thumbnailDir . DIRECTORY_SEPARATOR . $hash . '.jpg';
        imagejpeg($dst, $out, 85);
        imagedestroy($dst);
        imagedestroy($src);

        return $out;
    }

    private function applyOrientationWithGd(GdImage $image, ?int $orientation): GdImage
    {
        if ($orientation === null || $orientation === 1) {
            return $image;
        }

        switch ($orientation) {
            case 2:
                return $this->flipImage($image, IMG_FLIP_HORIZONTAL);
            case 3:
                return $this->rotateImage($image, 180);
            case 4:
                return $this->flipImage($image, IMG_FLIP_VERTICAL);
            case 5:
                $image = $this->flipImage($image, IMG_FLIP_HORIZONTAL);

                return $this->rotateImage($image, 90);
            case 6:
                return $this->rotateImage($image, -90);
            case 7:
                $image = $this->flipImage($image, IMG_FLIP_HORIZONTAL);

                return $this->rotateImage($image, -90);
            case 8:
                return $this->rotateImage($image, 90);
        }

        return $image;
    }

    private function rotateImage(GdImage $image, float $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function flipImage(GdImage $image, int $mode): GdImage
    {
        if (function_exists('imageflip')) {
            imageflip($image, $mode);

            return $image;
        }

        $width   = imagesx($image);
        $height  = imagesy($image);
        $flipped = imagecreatetruecolor($width, $height);

        switch ($mode) {
            case IMG_FLIP_HORIZONTAL:
                for ($x = 0; $x < $width; ++$x) {
                    imagecopy($flipped, $image, $width - $x - 1, 0, $x, 0, 1, $height);
                }

                break;
            case IMG_FLIP_VERTICAL:
                for ($y = 0; $y < $height; ++$y) {
                    imagecopy($flipped, $image, 0, $height - $y - 1, 0, $y, $width, 1);
                }

                break;
            case IMG_FLIP_BOTH:
                for ($x = 0; $x < $width; ++$x) {
                    for ($y = 0; $y < $height; ++$y) {
                        imagecopy($flipped, $image, $width - $x - 1, $height - $y - 1, $x, $y, 1, 1);
                    }
                }

                break;
            default:
                imagedestroy($flipped);

                return $image;
        }

        imagedestroy($image);

        return $flipped;
    }
}
