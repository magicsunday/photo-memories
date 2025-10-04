<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\FacePresenceDetector;
use MagicSunday\Memories\Service\Metadata\Support\FaceDetectionBackendInterface;
use MagicSunday\Memories\Service\Metadata\Support\FaceDetectionResult;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function chmod;
use function file_put_contents;
use function is_file;
use function is_string;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class FacePresenceDetectorTest extends TestCase
{
    #[Test]
    public function detectsFacesOnPosterFrame(): void
    {
        $videoBase = tempnam(sys_get_temp_dir(), 'video_');
        if ($videoBase === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        $videoPath = $videoBase . '.mp4';
        unlink($videoBase);
        file_put_contents($videoPath, 'video');

        $ffmpeg  = $this->createMockFfmpegBinary();
        $ffprobe = $this->createMockFfprobeBinary();

        $backend  = new RecordingFaceBackend(FaceDetectionResult::fromCount(1));
        $detector = new FacePresenceDetector($backend, $ffmpeg, $ffprobe, 1.0);

        try {
            $media = $this->makeMedia(
                id: 801,
                path: $videoPath,
                configure: static function (Media $media): void {
                    $media->setMime('video/mp4');
                    $media->setIsVideo(true);
                    $media->setWidth(1920);
                    $media->setHeight(1080);
                },
            );

            $detector->extract($videoPath, $media);

            self::assertSame(1, $backend->calls);
            self::assertTrue($media->hasFaces());
            self::assertSame(1, $media->getFacesCount());

            $posterPath = $backend->lastPath;
            self::assertNotNull($posterPath);
            self::assertTrue(is_string($posterPath));
            self::assertNotSame($videoPath, $posterPath);
            self::assertFalse(is_file($posterPath));
        } finally {
            if (is_file($videoPath)) {
                unlink($videoPath);
            }

            $this->cleanupBinary($ffmpeg);
            $this->cleanupBinary($ffprobe);
        }
    }

    #[Test]
    public function keepsDefaultsWhenBackendReportsNoFacesForPoster(): void
    {
        $videoBase = tempnam(sys_get_temp_dir(), 'video_');
        if ($videoBase === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        $videoPath = $videoBase . '.mp4';
        unlink($videoBase);
        file_put_contents($videoPath, 'video');

        $ffmpeg  = $this->createMockFfmpegBinary();
        $ffprobe = $this->createMockFfprobeBinary();

        $backend  = new RecordingFaceBackend(FaceDetectionResult::fromCount(0));
        $detector = new FacePresenceDetector($backend, $ffmpeg, $ffprobe, 1.0);

        try {
            $media = $this->makeMedia(
                id: 802,
                path: $videoPath,
                configure: static function (Media $media): void {
                    $media->setMime('video/mp4');
                    $media->setIsVideo(true);
                    $media->setWidth(1920);
                    $media->setHeight(1080);
                },
            );

            $detector->extract($videoPath, $media);

            self::assertSame(1, $backend->calls);
            self::assertFalse($media->hasFaces());
            self::assertSame(0, $media->getFacesCount());
        } finally {
            if (is_file($videoPath)) {
                unlink($videoPath);
            }

            $this->cleanupBinary($ffmpeg);
            $this->cleanupBinary($ffprobe);
        }
    }

    private function createMockFfmpegBinary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ffmpeg_');
        if ($path === false) {
            self::fail('Unable to allocate temporary ffmpeg binary.');
        }

        $script = <<<'PHP'
#!/usr/bin/env php
<?php
$args = $_SERVER['argv'] ?? [];
$output = $args[count($args) - 1] ?? null;
if (!is_string($output) || $output === '') {
    fwrite(STDERR, "missing output path\n");
    exit(1);
}

$image = imagecreatetruecolor(8, 8);
$color = imagecolorallocate($image, 255, 0, 0);
imagefilledrectangle($image, 0, 0, 7, 7, $color);
if (imagejpeg($image, $output) !== true) {
    fwrite(STDERR, "failed to write poster\n");
    imagedestroy($image);
    exit(1);
}

imagedestroy($image);
exit(0);
PHP;

        file_put_contents($path, $script);
        chmod($path, 0o755);

        return $path;
    }

    private function createMockFfprobeBinary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ffprobe_');
        if ($path === false) {
            self::fail('Unable to allocate temporary ffprobe binary.');
        }

        $script = <<<'PHP'
#!/usr/bin/env php
<?php
fwrite(STDOUT, "2.0\n");
PHP;

        file_put_contents($path, $script);
        chmod($path, 0o755);

        return $path;
    }

    private function cleanupBinary(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

final class RecordingFaceBackend implements FaceDetectionBackendInterface
{
    public int $calls = 0;

    public ?string $lastPath = null;

    public function __construct(private readonly FaceDetectionResult $result)
    {
    }

    public function detectFaces(string $imagePath, Media $media): FaceDetectionResult
    {
        ++$this->calls;
        $this->lastPath = $imagePath;

        return $this->result;
    }
}
