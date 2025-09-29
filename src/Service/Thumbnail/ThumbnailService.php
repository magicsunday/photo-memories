<?php

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Thumbnail;

use RuntimeException;
use Imagick;
use MagicSunday\Memories\Entity\Media;

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
        $this->sizes = $sizes;
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
            $path = $this->generateThumbnail($filepath, $size);
            if ($path !== null) {
                $results[$size] = $path;
            }
        }

        return $results;
    }

    private function generateThumbnail(string $filepath, int $width): ?string
    {
        if (extension_loaded('imagick')) {
            return $this->generateWithImagick($filepath, $width);
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->generateWithGd($filepath, $width);
        }

        throw new RuntimeException('No available image library (Imagick or GD) to create thumbnails');
    }

    private function generateWithImagick(string $filepath, int $width): ?string
    {
        $im = new Imagick();
        $im->setOption('jpeg:preserve-settings', 'true');
        $im->readImage($filepath . '[0]');
        $im->thumbnailImage($width, 0);

        $hash = hash('crc32b', $filepath . ':' . $width);
        $out = $this->thumbnailDir . DIRECTORY_SEPARATOR . $hash . '.jpg';
        if ($im->writeImage($out)) {
            $im->clear();
            return $out;
        }

        $im->clear();
        return null;
    }

    private function generateWithGd(string $filepath, int $width): ?string
    {
        $data = @file_get_contents($filepath);
        if ($data === false) {
            return null;
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $ratio = $h > 0 ? ($w / $h) : 1;
        $newW = $width;
        $newH = (int)round($width / $ratio);
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        $hash = hash('crc32b', $filepath . ':' . $width);
        $out = $this->thumbnailDir . DIRECTORY_SEPARATOR . $hash . '.jpg';
        imagejpeg($dst, $out, 85);
        imagedestroy($dst);
        imagedestroy($src);
        return $out;
    }
}
