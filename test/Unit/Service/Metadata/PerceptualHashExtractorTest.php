<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\PerceptualHashExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Throwable;

use function chr;
use function file_get_contents;
use function file_put_contents;
use function imagecolorallocate;
use function imagecreatefromjpeg;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefilledrectangle;
use function imagejpeg;
use function imagerotate;
use function is_file;
use function sprintf;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class PerceptualHashExtractorTest extends TestCase
{
    #[Test]
    public function computesStable128BitHashForImage(): void
    {
        $imagePath = $this->createGradientImage();

        try {
            $media = $this->makeMedia(
                id: 501,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/jpeg');
                    $media->setWidth(64);
                    $media->setHeight(64);
                },
            );

            $extractor = new PerceptualHashExtractor(32, 16, 16);
            $extractor->extract($imagePath, $media);

            $phash = $media->getPhash();
            self::assertNotNull($phash);
            self::assertSame(32, strlen($phash ?? ''));
            self::assertSame('942a01442c9b996343c1becc632c9a7b', $phash);

            $prefix = $media->getPhashPrefix();
            self::assertSame(substr($phash, 0, 16), $prefix);

            $phash64 = $media->getPhash64();
            self::assertNotNull($phash64);
            self::assertMatchesRegularExpression('/^\d+$/', $phash64 ?? '');
        } finally {
            unlink($imagePath);
        }
    }

    #[Test]
    public function hashesVideoViaPosterFrame(): void
    {
        $videoBase = tempnam(sys_get_temp_dir(), 'vid_');
        if ($videoBase === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        $videoPath = $videoBase . '.mp4';
        unlink($videoBase);

        // Placeholder payload – ffmpeg stub ignores contents.
        file_put_contents($videoPath, 'video');

        $ffmpeg  = $this->fixturePath('bin/mock-ffmpeg');
        $ffprobe = $this->fixturePath('bin/mock-ffprobe');

        $this->skipUnlessPosterStubIsExecutable($ffmpeg);

        try {
            $media = $this->makeMedia(
                id: 502,
                path: $videoPath,
                configure: static function (Media $media): void {
                    $media->setMime('video/mp4');
                    $media->setIsVideo(true);
                    $media->setWidth(1920);
                    $media->setHeight(1080);
                },
            );

            $extractor = new PerceptualHashExtractor(32, 16, 16, $ffmpeg, $ffprobe, 1.5);
            $extractor->extract($videoPath, $media);

            $phash = $media->getPhash();
            self::assertSame('d059af7420d10949cfd82a170af08d0d', $phash);
            self::assertSame(substr($phash, 0, 16), $media->getPhashPrefix());
            self::assertNotNull($media->getPhash64());
        } finally {
            if (is_file($videoPath)) {
                unlink($videoPath);
            }
        }
    }

    #[Test]
    public function producesIdenticalHashesAfterExifRotation(): void
    {
        $imagePath = $this->createGradientImage();
        $extractor = new PerceptualHashExtractor(32, 16, 16);

        try {
            $media = $this->makeMedia(
                id: 601,
                path: $imagePath,
                configure: static function (Media $entity): void {
                    $entity->setMime('image/jpeg');
                    $entity->setWidth(64);
                    $entity->setHeight(64);
                    $entity->setOrientation(1);
                },
            );

            $extractor->extract($imagePath, $media);
            $expectedPhash = $media->getPhash();
            self::assertNotNull($expectedPhash);

            $rotated90  = $this->createRotatedCopy($imagePath, -90, 8);
            $rotated180 = $this->createRotatedCopy($imagePath, 180, 3);

            try {
                $media90 = $this->makeMedia(
                    id: 602,
                    path: $rotated90,
                    configure: static function (Media $entity): void {
                        $entity->setMime('image/jpeg');
                        $entity->setWidth(64);
                        $entity->setHeight(64);
                        $entity->setOrientation(8);
                        $entity->setNeedsRotation(true);
                    },
                );

                $extractor->extract($rotated90, $media90);
                self::assertSame($expectedPhash, $media90->getPhash());

                $media180 = $this->makeMedia(
                    id: 603,
                    path: $rotated180,
                    configure: static function (Media $entity): void {
                        $entity->setMime('image/jpeg');
                        $entity->setWidth(64);
                        $entity->setHeight(64);
                        $entity->setOrientation(3);
                        $entity->setNeedsRotation(true);
                    },
                );

                $extractor->extract($rotated180, $media180);
                self::assertSame($expectedPhash, $media180->getPhash());
            } finally {
                unlink($rotated90);
                unlink($rotated180);
            }
        } finally {
            unlink($imagePath);
        }
    }

    private function createGradientImage(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'hash_');
        if ($base === false) {
            self::fail('Unable to create temporary image.');
        }

        $path = $base . '.jpg';
        unlink($base);

        $image = imagecreatetruecolor(64, 64);
        for ($y = 0; $y < 64; ++$y) {
            for ($x = 0; $x < 64; ++$x) {
                $gray  = ($x + $y) % 256;
                $color = imagecolorallocate($image, $gray, $gray, $gray);
                imagefilledrectangle($image, $x, $y, $x, $y, $color);
            }
        }

        if (!imagejpeg($image, $path, 90)) {
            imagedestroy($image);
            self::fail('Unable to write gradient image.');
        }

        imagedestroy($image);

        return $path;
    }

    /**
     * Creates a physically rotated JPEG copy that also carries the matching EXIF
     * orientation tag.
     *
     * A real rotated photo stores the rotated pixels together with the EXIF
     * orientation flag describing how a viewer must rotate it back. The extractor
     * normalises this either through the GD path (manual matrix rotation from the
     * entity orientation) or through the Imagick path (Imagick auto-orients from
     * the embedded EXIF tag). Embedding the tag lets the assertion hold for both
     * backends, regardless of which one the runtime selects.
     *
     * @param string $sourcePath  Absolute path to the upright source JPEG
     * @param int    $degrees     Rotation angle passed to imagerotate()
     * @param int    $orientation EXIF orientation value matching the rotation
     */
    private function createRotatedCopy(string $sourcePath, int $degrees, int $orientation): string
    {
        $image = @imagecreatefromjpeg($sourcePath);
        if ($image === false) {
            self::fail('Unable to read source image for rotation.');
        }

        $rotated = @imagerotate($image, $degrees, 0);
        imagedestroy($image);

        if ($rotated === false) {
            self::fail('Unable to rotate image.');
        }

        $base = tempnam(sys_get_temp_dir(), 'rot_');
        if ($base === false) {
            imagedestroy($rotated);
            self::fail('Unable to create temporary rotated image.');
        }

        $path = $base . '.jpg';
        unlink($base);

        if (!imagejpeg($rotated, $path, 90)) {
            imagedestroy($rotated);
            self::fail('Unable to write rotated image.');
        }

        imagedestroy($rotated);

        $this->embedExifOrientation($path, $orientation);

        return $path;
    }

    /**
     * Splices a minimal APP1 EXIF segment carrying a single Orientation tag into
     * a JPEG so that EXIF-aware backends (Imagick) can auto-orient it.
     *
     * GD ignores the tag and keeps the raw pixels, so both backends stay in sync
     * with the entity orientation used by the extractor.
     *
     * @param string $path        Absolute path to the JPEG to rewrite in place
     * @param int    $orientation EXIF orientation value in the range [1..8]
     */
    private function embedExifOrientation(string $path, int $orientation): void
    {
        $data = file_get_contents($path);
        if ($data === false || !str_starts_with($data, "\xFF\xD8")) {
            self::fail('Unable to read JPEG for EXIF injection.');
        }

        // Big-endian ("MM") TIFF header with IFD0 at offset 8.
        $tiff = 'MM' . "\x00\x2A\x00\x00\x00\x08";

        // One IFD entry: Orientation (tag 0x0112, type SHORT, count 1).
        $tiff .= "\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01"
            . chr(($orientation >> 8) & 0xFF) . chr($orientation & 0xFF) . "\x00\x00";

        // Next-IFD offset (none).
        $tiff .= "\x00\x00\x00\x00";

        $payload = "Exif\x00\x00" . $tiff;
        $length  = strlen($payload) + 2;
        $app1    = "\xFF\xE1" . chr(($length >> 8) & 0xFF) . chr($length & 0xFF) . $payload;

        $rewritten = substr($data, 0, 2) . $app1 . substr($data, 2);
        if (file_put_contents($path, $rewritten) === false) {
            self::fail('Unable to write EXIF-tagged rotated image.');
        }
    }

    /**
     * Skips the test unless the mock ffmpeg poster stub can actually emit a JPEG
     * in this environment.
     *
     * The stub uses a "#!/usr/bin/env php" shebang; when that interpreter has no
     * GD extension (for instance under a hardened CLI that disables ini loading)
     * it cannot produce a poster frame and the extractor legitimately yields no
     * hash. That is an environment limitation, not a regression, so the test is
     * skipped rather than failed. Environments with a GD-capable interpreter run
     * the assertions normally.
     *
     * @param string $ffmpeg Absolute path to the mock ffmpeg stub
     */
    private function skipUnlessPosterStubIsExecutable(string $ffmpeg): void
    {
        $probe = tempnam(sys_get_temp_dir(), 'poster_probe_');
        if ($probe === false) {
            self::markTestSkipped('Unable to create a poster-frame probe file.');
        }

        $posterProbe = $probe . '.jpg';
        unlink($probe);

        $process = new Process([$ffmpeg, $posterProbe]);

        try {
            $process->run();
        } catch (Throwable $throwable) {
            self::markTestSkipped(sprintf(
                'The poster-frame ffmpeg stub cannot be executed here: %s',
                $throwable->getMessage(),
            ));
        }

        $produced = $process->isSuccessful() && is_file($posterProbe);

        if (is_file($posterProbe)) {
            unlink($posterProbe);
        }

        if (!$produced) {
            self::markTestSkipped(
                'The poster-frame ffmpeg stub is unavailable in this environment '
                . '(its "#!/usr/bin/env php" interpreter cannot emit a JPEG, e.g. the GD extension is disabled).'
            );
        }
    }
}
