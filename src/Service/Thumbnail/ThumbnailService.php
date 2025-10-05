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
use Throwable;
use function extension_loaded;
use function function_exists;
use function is_int;
use function method_exists;
use function sprintf;

/**
 * Thumbnail service that generates JPEG thumbnails using Imagick or GD.
 */
class ThumbnailService implements ThumbnailServiceInterface
{
    private const int ORIENTATION_UNDEFINED = 0;
    private const int ORIENTATION_TOPLEFT   = 1;
    private const int ORIENTATION_TOPRIGHT  = 2;
    private const int ORIENTATION_BOTTOMRIGHT = 3;
    private const int ORIENTATION_BOTTOMLEFT  = 4;
    private const int ORIENTATION_LEFTTOP     = 5;
    private const int ORIENTATION_RIGHTTOP    = 6;
    private const int ORIENTATION_RIGHTBOTTOM = 7;
    private const int ORIENTATION_LEFTBOTTOM  = 8;

    private const int JPEG_QUALITY = 85;

    /**
     * Absolute path to the thumbnail output directory.
     */
    private readonly string $thumbnailDir;

    /**
     * @var list<int> $sizes List of thumbnail widths that should be generated.
     */
    private readonly array $sizes;

    /**
     * Whether thumbnails should apply the EXIF orientation.
     */
    private readonly bool $applyOrientation;

    /**
     * @param string $thumbnailDir    Absolute path to the thumbnail directory.
     * @param int[]  $sizes           Desired thumbnail widths (in pixels).
     * @param bool   $applyOrientation Whether the EXIF orientation should be applied.
     */
    public function __construct(string $thumbnailDir, array $sizes = [320, 1024], bool $applyOrientation = false)
    {
        $this->thumbnailDir     = $thumbnailDir;
        $this->sizes            = $sizes;
        $this->applyOrientation = $applyOrientation;

        // Ensure the output directory exists before thumbnails are generated.
        if (!is_dir($this->thumbnailDir)) {
            if (\file_exists($this->thumbnailDir)) {
                throw new RuntimeException(
                    sprintf('Failed to create thumbnail directory "%s".', $this->thumbnailDir),
                );
            }

            if (!mkdir($this->thumbnailDir, 0755, true) && !is_dir($this->thumbnailDir)) {
                throw new RuntimeException(
                    sprintf('Failed to create thumbnail directory "%s".', $this->thumbnailDir),
                );
            }
        }
    }

    /**
     * Generate thumbnails for a media file.
     *
     * @param string $filepath   Absolute path to the original media file.
     * @param Media  $media      Media metadata containing orientation and checksum.
     *
     * @return array<int, string> Map of thumbnail width to created file path.
     */
    public function generateAll(string $filepath, Media $media): array
    {
        $orientation = $media->getOrientation();
        $checksum    = $media->getChecksum();
        $hasImagick  = extension_loaded('imagick');
        $hasGd       = function_exists('imagecreatefromstring');
        $requiresImagick = $media->isRaw() || $media->isHeic();

        // Abort early when no supported imaging library is available at all.
        if (!$hasImagick && !$hasGd) {
            throw new RuntimeException('No available image library (Imagick or GD) to create thumbnails');
        }

        if ($requiresImagick && !$hasImagick) {
            throw new RuntimeException('Imagick is required to create thumbnails for RAW/HEIC media');
        }

        if ($hasImagick) {
            try {
                return $this->generateThumbnailsWithImagick($filepath, $orientation, $this->sizes, $checksum);
            } catch (ImagickException $exception) {
                if ($requiresImagick) {
                    throw new RuntimeException('Imagick is required to create thumbnails for RAW/HEIC media', 0, $exception);
                }

                if (!$hasGd) {
                    throw new RuntimeException('No available image library (Imagick or GD) to create thumbnails', 0, $exception);
                }

                // Imagick failed, therefore fall back to the GD implementation below.
            }
        }

        if ($hasGd && !$requiresImagick) {
            return $this->generateThumbnailsWithGd($filepath, $orientation, $this->sizes, $checksum, $media->getMime());
        }

        throw new RuntimeException('No available image library (Imagick or GD) to create thumbnails');
    }

    /**
     * Creates a new Imagick instance. Kept separate for easier testing.
     *
     * @return Imagick
     */
    protected function createImagick(): Imagick
    {
        return new Imagick();
    }

    /**
     * @param string   $filepath    Absolute path to the original media file.
     * @param int|null $orientation EXIF orientation value of the source media.
     * @param int[]    $sizes       Desired thumbnail widths (in pixels).
     * @param string   $checksum    Media checksum used for generating file names.
     *
     * @return array<int, string>
     * @throws ImagickException
     */
    private function generateThumbnailsWithImagick(string $filepath, ?int $orientation, array $sizes, string $checksum): array
    {
        $imagick = $this->createImagick();

        try {
            $imagick->setOption('jpeg:preserve-settings', 'true');
            $imagick->readImage($filepath . '[0]');

            if ($this->applyOrientation) {
                $this->applyOrientationWithImagick($imagick, $orientation);
            }

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

                // Remember that this width was already generated to avoid duplicates.
                $generatedKeys[$targetWidth] = true;

                $clone = $this->cloneImagick($imagick);

                try {
                    $resizeResult = $clone->thumbnailImage($targetWidth, 0);

                    if ($resizeResult === false) {
                        throw new ImagickException(
                            sprintf('Unable to resize image for thumbnail width %d.', $targetWidth)
                        );
                    }

                    $clone->setImageBackgroundColor(new ImagickPixel('white'));

                    try {
                        $clone->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                    } catch (ImagickException) {
                        try {
                            $clone->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
                        } catch (ImagickException | Throwable) {
                            // Legacy builds may not support manipulating the alpha channel explicitly.
                        }
                    } catch (Throwable) {
                        // Ignore missing support for setImageAlphaChannel on legacy Imagick versions.
                    }

                    try {
                        $flattened = $clone->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                        $clone->clear();
                        $clone = $flattened;
                    } catch (ImagickException | Throwable) {
                        try {
                            $flattened = $clone->flattenImages();
                            $clone->clear();
                            $clone = $flattened;
                        } catch (Throwable) {
                            // Keep the original instance when flattening is not supported.
                        }
                    }

                    try {
                        $clone->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
                    } catch (ImagickException | Throwable) {
                        // Ignore when the alpha channel cannot be forced to opaque.
                    }

                    $clone->setImageFormat('jpeg');
                    $clone->setImageCompression(Imagick::COMPRESSION_JPEG);
                    $clone->setImageCompressionQuality(self::JPEG_QUALITY);

                    $out         = $this->buildThumbnailPath($checksum, $targetWidth);
                    $writeResult = $clone->writeImage($out);

                    if ($writeResult === false) {
                        throw new ImagickException(
                            sprintf('Unable to create thumbnail at path "%s".', $out)
                        );
                    }

                    $results[$targetWidth] = $out;
                } finally {
                    try {
                        $clone->clear();
                    } finally {
                        try {
                            $clone->destroy();
                        } catch (ImagickException | Throwable) {
                            // Ignore destruction errors on cloned instances.
                        }

                        unset($clone);
                    }
                }
            }

            return $results;
        } finally {
            try {
                $imagick->clear();
            } finally {
                try {
                    $imagick->destroy();
                } catch (ImagickException | Throwable) {
                    // Ignore destruction errors during cleanup.
                }

                unset($imagick);
            }
        }
    }

    /**
     * @param string      $filepath   Absolute path to the original media file.
     * @param int|null    $orientation EXIF orientation value of the source media.
     * @param int[]       $sizes        Desired thumbnail widths (in pixels).
     * @param string      $checksum     Media checksum used for generating file names.
     * @param string|null $mime         Optional MIME type hint for GD loader selection.
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

                // Try to locate a project specific GD loader before using the global function.
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
                throw new RuntimeException(
                    sprintf('Unable to read image data from "%s" for thumbnail generation.', $filepath));
            }

            // Fall back to the generic loader which can infer the format from the binary data.
            $src = @imagecreatefromstring($data);
        }

        if (!$src instanceof GdImage) {
            throw new RuntimeException(
                sprintf('Unable to create GD image from "%s".', $filepath));
        }

        if ($this->applyOrientation) {
            $src = $this->applyOrientationWithGd($src, $orientation);
        }

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

                // Remember that this width was already generated to avoid duplicates.
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
                    $writeResult = @imagejpeg($dst, $out, self::JPEG_QUALITY);
                } finally {
                    imagedestroy($dst);
                }

                if ($writeResult === false) {
                    throw new RuntimeException(
                        sprintf('Unable to create thumbnail at path "%s".', $out));
                }

                $results[$newWidth] = $out;
            }

            return $results;
        } finally {
            imagedestroy($src);
        }
    }

    /**
     * @param Imagick $imagick Imagick instance that should be cloned.
     *
     * @return Imagick
     */
    protected function cloneImagick(Imagick $imagick): Imagick
    {
        return clone $imagick;
    }

    /**
     * Builds the target path for a generated thumbnail.
     *
     * @param string $checksum Media checksum used for naming.
     * @param int    $width    Thumbnail width in pixels.
     *
     * @return string
     */
    private function buildThumbnailPath(string $checksum, int $width): string
    {
        return $this->thumbnailDir . DIRECTORY_SEPARATOR . $checksum . '-' . $width . '.jpg';
    }

    /**
     * Applies the EXIF orientation to a GD image resource.
     *
     * @param GdImage   $image       GD image resource to transform.
     * @param int|null  $orientation EXIF orientation value or null when unavailable.
     *
     * @return GdImage
     */
    protected function applyOrientationWithGd(GdImage $image, ?int $orientation): GdImage
    {
        if ($orientation === null || $orientation === self::ORIENTATION_TOPLEFT) {
            return $image;
        }

        switch ($orientation) {
            case self::ORIENTATION_TOPRIGHT:
                return $this->flipImage($image, IMG_FLIP_HORIZONTAL);
            case self::ORIENTATION_BOTTOMRIGHT:
                return $this->rotateImage($image, 180);
            case self::ORIENTATION_BOTTOMLEFT:
                return $this->flipImage($image, IMG_FLIP_VERTICAL);
            case self::ORIENTATION_LEFTTOP:
                $image = $this->flipImage($image, IMG_FLIP_HORIZONTAL);

                return $this->rotateImage($image, 90);
            case self::ORIENTATION_RIGHTTOP:
                return $this->rotateImage($image, -90);
            case self::ORIENTATION_RIGHTBOTTOM:
                $image = $this->flipImage($image, IMG_FLIP_HORIZONTAL);

                return $this->rotateImage($image, -90);
            case self::ORIENTATION_LEFTBOTTOM:
                return $this->rotateImage($image, 90);
        }

        return $image;
    }

    /**
     * Applies the EXIF orientation to an Imagick instance.
     *
     * @param Imagick  $imagick     Imagick instance to transform.
     * @param int|null $orientation EXIF orientation value or null when unavailable.
     *
     * @throws ImagickException
     */
    protected function applyOrientationWithImagick(Imagick $imagick, ?int $orientation): void
    {
        $targetOrientation = $orientation;

        if ($orientation !== null && $orientation >= self::ORIENTATION_TOPLEFT && $orientation <= self::ORIENTATION_LEFTBOTTOM) {
            try {
                $imagick->setImageOrientation($orientation);
            } catch (ImagickException | Throwable) {
                // Continue with the provided orientation when setImageOrientation is unsupported.
            }
        }

        try {
            if (method_exists($imagick, 'autoOrientImage')) {
                if ($targetOrientation !== null) {
                    $imagick->setImageOrientation($targetOrientation);
                }

                $imagick->autoOrientImage();
                $imagick->setImageOrientation(self::ORIENTATION_TOPLEFT);

                return;
            }
        } catch (ImagickException | Throwable) {
            // Fall back to the manual implementation when autoOrientImage is unavailable.
        }

        $effectiveOrientation = $imagick->getImageOrientation();

        if ($effectiveOrientation === self::ORIENTATION_UNDEFINED && $targetOrientation !== null) {
            $effectiveOrientation = $targetOrientation;
        }

        $this->applyOrientationWithLegacyImagick($imagick, $effectiveOrientation);

        try {
            $imagick->setImageOrientation(self::ORIENTATION_TOPLEFT);
        } catch (ImagickException | Throwable) {
            // Ignore when the Imagick build cannot reset the orientation flag.
        }
    }

    /**
     * Applies the orientation manually for Imagick versions lacking autoOrientImage().
     *
     * @param Imagick $imagick Imagick instance to transform.
     *
     * @throws ImagickException
     */
    private function applyOrientationWithLegacyImagick(Imagick $imagick, int $orientation): void
    {
        switch ($orientation) {
            case self::ORIENTATION_UNDEFINED:
            case self::ORIENTATION_TOPLEFT:
                return;
            case self::ORIENTATION_TOPRIGHT:
                $imagick->flopImage();

                return;
            case self::ORIENTATION_BOTTOMRIGHT:
                $imagick->rotateImage(new ImagickPixel('none'), 180);

                return;
            case self::ORIENTATION_BOTTOMLEFT:
                $imagick->flipImage();

                return;
            case self::ORIENTATION_LEFTTOP:
                $imagick->flopImage();
                $imagick->rotateImage(new ImagickPixel('none'), 90);

                return;
            case self::ORIENTATION_RIGHTTOP:
                $imagick->rotateImage(new ImagickPixel('none'), -90);

                return;
            case self::ORIENTATION_RIGHTBOTTOM:
                $imagick->flopImage();
                $imagick->rotateImage(new ImagickPixel('none'), -90);

                return;
            case self::ORIENTATION_LEFTBOTTOM:
                $imagick->rotateImage(new ImagickPixel('none'), 90);

                return;
        }
    }

    /**
     * Rotates a GD image by the provided degrees.
     *
     * @param GdImage $image   GD image resource to rotate.
     * @param float   $degrees Degrees to rotate (clockwise direction).
     *
     * @return GdImage
     */
    private function rotateImage(GdImage $image, float $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * Mirrors a GD image according to the provided mode.
     *
     * @param GdImage $image GD image resource to mirror.
     * @param int     $mode  GD flip mode constant.
     *
     * @return GdImage
     */
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
