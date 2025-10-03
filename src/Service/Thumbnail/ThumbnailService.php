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
use ImagickException;
use ImagickPixel;
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
        if (!is_dir($this->thumbnailDir) && !mkdir($this->thumbnailDir, 0755, true) && !is_dir($this->thumbnailDir)) {
            throw new RuntimeException(sprintf('Failed to create thumbnail directory "%s".', $this->thumbnailDir));
        }
    }

    /**
     * Generate thumbnails for a media file.
     *
     * @return array map size => path
     */
    public function generateAll(string $filepath, Media $media): array
    {
        $orientation = $media->getOrientation();
        $checksum    = $media->getChecksum();

        if (extension_loaded('imagick')) {
            try {
                return $this->generateThumbnailsWithImagick($filepath, $orientation, $this->sizes, $checksum);
            } catch (ImagickException | RuntimeException $exception) {
                if (function_exists('imagecreatefromstring')) {
                    try {
                        return $this->generateThumbnailsWithGd(
                            $filepath,
                            $orientation,
                            $this->sizes,
                            $checksum,
                            $media->getMime(),
                        );
                    } catch (RuntimeException $gdException) {
                        throw $gdException;
                    }
                }

                if ($exception instanceof RuntimeException) {
                    throw $exception;
                }

                throw new RuntimeException('No available image library (Imagick or GD) to create thumbnails', 0, $exception);
            }
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->generateThumbnailsWithGd($filepath, $orientation, $this->sizes, $checksum, $media->getMime());
        }

        throw new RuntimeException('No available image library (Imagick or GD) to create thumbnails');
    }

    private function generateWithImagick(string $filepath, int $width, ?int $orientation, string $checksum): ?string
    {
        $results = $this->generateThumbnailsWithImagick($filepath, $orientation, [$width], $checksum);

        if (isset($results[$width])) {
            return $results[$width];
        }

        if ($results === []) {
            return null;
        }

        $paths = array_values($results);

        return $paths[0] ?? null;
    }

    protected function createImagick(): Imagick
    {
        return new Imagick();
    }

    private function generateWithGd(string $filepath, int $width, ?int $orientation, string $checksum): ?string
    {
        $results = $this->generateThumbnailsWithGd($filepath, $orientation, [$width], $checksum);

        if (isset($results[$width])) {
            return $results[$width];
        }

        if ($results === []) {
            return null;
        }

        $paths = array_values($results);

        return $paths[0] ?? null;
    }

    /**
     * @param int[] $sizes
     *
     * @return array<int, string>
     */
    private function generateThumbnailsWithImagick(string $filepath, ?int $orientation, array $sizes, string $checksum): array
    {
        $imagick = $this->createImagick();

        try {
            $imagick->setOption('jpeg:preserve-settings', 'true');
            $imagick->readImage($filepath . '[0]');

            $this->applyOrientationWithImagick($imagick, $orientation);

            $sourceWidth = $imagick->getImageWidth();

            $results       = [];
            $generatedKeys = [];
            foreach ($sizes as $size) {
                $targetWidth = min($size, $sourceWidth);

                if ($targetWidth <= 0) {
                    continue;
                }

                if (isset($generatedKeys[$targetWidth])) {
                    continue;
                }

                $generatedKeys[$targetWidth] = true;

                $clone = $this->cloneImagick($imagick);

                try {
                    $resizeResult = $clone->thumbnailImage($targetWidth, 0);

                    if ($resizeResult === false) {
                        throw new RuntimeException(sprintf('Unable to resize image for thumbnail width %d.', $targetWidth));
                    }

                    $clone->setImageBackgroundColor('white');

                    if (method_exists($clone, 'setImageAlphaChannel')) {
                        $clone->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                    }

                    if ($clone->getImageAlphaChannel()) {
                        $flattened = $clone->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                        $clone->clear();
                        $clone->destroy();
                        $clone = $flattened;
                    }

                    $clone->setImageFormat('jpeg');
                    $clone->setImageCompression(Imagick::COMPRESSION_JPEG);
                    $clone->setImageCompressionQuality(85);

                    $out         = $this->buildThumbnailPath($checksum, $targetWidth);
                    $writeResult = $clone->writeImage($out);

                    if ($writeResult === false) {
                        throw new RuntimeException(sprintf('Unable to create thumbnail at path "%s".', $out));
                    }

                    $results[$targetWidth] = $out;
                } finally {
                    $clone->clear();
                    $clone->destroy();
                }
            }

            return $results;
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    /**
     * @param int[]       $sizes
     * @param string|null $mime
     *
     * @return array<int, string>
     */
    private function generateThumbnailsWithGd(string $filepath, ?int $orientation, array $sizes, string $checksum, ?string $mime = null): array
    {
        $src = null;

        if ($mime !== null) {
            $mime = strtolower($mime);
            $loaders = [
                'image/jpeg' => 'imagecreatefromjpeg',
                'image/jpg' => 'imagecreatefromjpeg',
                'image/pjpeg' => 'imagecreatefromjpeg',
                'image/png' => 'imagecreatefrompng',
                'image/webp' => 'imagecreatefromwebp',
                'image/gif' => 'imagecreatefromgif',
            ];

            if (isset($loaders[$mime])) {
                $loader            = $loaders[$mime];
                $namespacedLoader  = __NAMESPACE__ . '\\' . $loader;
                $selectedLoader    = null;

                if (function_exists($namespacedLoader)) {
                    $selectedLoader = $namespacedLoader;
                } elseif (function_exists($loader)) {
                    $selectedLoader = $loader;
                }

                if ($selectedLoader !== null) {
                    $src = @$selectedLoader($filepath);
                }
            }
        }

        if (!$src instanceof GdImage) {
            $data = @file_get_contents($filepath);
            if ($data === false) {
                throw new RuntimeException(sprintf('Unable to read image data from "%s" for thumbnail generation.', $filepath));
            }

            $src = @imagecreatefromstring($data);
        }

        if (!$src instanceof GdImage) {
            throw new RuntimeException(sprintf('Unable to create GD image from "%s".', $filepath));
        }

        $src = $this->applyOrientationWithGd($src, $orientation);

        $width  = imagesx($src);
        $height = imagesy($src);
        $ratio  = $height > 0 ? ($width / $height) : 1;

        try {
            $results       = [];
            $generatedKeys = [];
            foreach ($sizes as $size) {
                $newWidth = min($size, $width);

                if ($newWidth <= 0) {
                    continue;
                }

                if (isset($generatedKeys[$newWidth])) {
                    continue;
                }

                $generatedKeys[$newWidth] = true;

                $newHeight = (int) round($newWidth / $ratio);

                if ($newHeight <= 0) {
                    continue;
                }
                $dst       = imagecreatetruecolor($newWidth, $newHeight);

                if (!$dst instanceof GdImage) {
                    throw new RuntimeException('Unable to create GD thumbnail resource.');
                }

                try {
                    imagealphablending($dst, true);

                    $backgroundColor = imagecolorallocate($dst, 255, 255, 255);

                    if (!is_int($backgroundColor)) {
                        throw new RuntimeException('Unable to allocate background color for thumbnail.');
                    }

                    $fillResult = imagefill($dst, 0, 0, $backgroundColor);

                    if ($fillResult === false) {
                        throw new RuntimeException('Unable to fill thumbnail background.');
                    }

                    $resampleResult = imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    if ($resampleResult === false) {
                        throw new RuntimeException('Unable to resample image for thumbnail.');
                    }

                    $out         = $this->buildThumbnailPath($checksum, $newWidth);
                    $writeResult = @imagejpeg($dst, $out, 85);
                } finally {
                    imagedestroy($dst);
                }

                if ($writeResult === false) {
                    throw new RuntimeException(sprintf('Unable to create thumbnail at path "%s".', $out));
                }

                $results[$newWidth] = $out;
            }

            return $results;
        } finally {
            imagedestroy($src);
        }
    }

    protected function cloneImagick(Imagick $imagick): Imagick
    {
        return clone $imagick;
    }

    private function buildThumbnailPath(string $checksum, int $width): string
    {
        return $this->thumbnailDir . DIRECTORY_SEPARATOR . $checksum . '-' . $width . '.jpg';
    }

    protected function applyOrientationWithGd(GdImage $image, ?int $orientation): GdImage
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

    protected function applyOrientationWithImagick(Imagick $imagick, ?int $orientation): void
    {
        if ($orientation !== null && $orientation >= 1 && $orientation <= 8) {
            $imagick->setImageOrientation($orientation);
        }

        $this->applyOrientationWithLegacyImagick($imagick);

        $imagick->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Applies the orientation manually for Imagick versions lacking autoOrientImage().
     */
    private function applyOrientationWithLegacyImagick(Imagick $imagick): void
    {
        $orientation = $imagick->getImageOrientation();

        switch ($orientation) {
            case Imagick::ORIENTATION_UNDEFINED:
            case Imagick::ORIENTATION_TOPLEFT:
                return;
            case Imagick::ORIENTATION_TOPRIGHT:
                $imagick->flopImage();

                return;
            case Imagick::ORIENTATION_BOTTOMRIGHT:
                $imagick->rotateImage(new ImagickPixel('none'), 180);

                return;
            case Imagick::ORIENTATION_BOTTOMLEFT:
                $imagick->flipImage();

                return;
            case Imagick::ORIENTATION_LEFTTOP:
                $imagick->flopImage();
                $imagick->rotateImage(new ImagickPixel('none'), 90);

                return;
            case Imagick::ORIENTATION_RIGHTTOP:
                $imagick->rotateImage(new ImagickPixel('none'), -90);

                return;
            case Imagick::ORIENTATION_RIGHTBOTTOM:
                $imagick->flopImage();
                $imagick->rotateImage(new ImagickPixel('none'), -90);

                return;
            case Imagick::ORIENTATION_LEFTBOTTOM:
                $imagick->rotateImage(new ImagickPixel('none'), 90);

                return;
        }
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
