<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Quality;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Quality\DeterministicImageQualityEstimator;
use MagicSunday\Memories\Service\Clusterer\Quality\VideoFrameSampler;
use MagicSunday\Memories\Service\Clusterer\Quality\VideoFrameSamplerInterface;
use PHPUnit\Framework\TestCase;

use function array_key_last;
use function file_put_contents;
use function filesize;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagejpeg;
use function imagesetpixel;
use function imagedestroy;
use function ob_get_clean;
use function ob_start;
use function rename;
use function sha1;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * @covers \MagicSunday\Memories\Service\Clusterer\Quality\DeterministicImageQualityEstimator
 */
final class DeterministicImageQualityEstimatorTest extends TestCase
{
    public function testScoreVideoUsesFfprobeSample(): void
    {
        $videoPath = $this->createTemporaryFile('video_', '.mp4');
        file_put_contents($videoPath, 'stub');

        $media = $this->createVideoMedia($videoPath);
        $media->setVideoFps(48.0);

        $frameData = $this->createSampleFrameBytes();
        $commands  = [];

        $runner = function (array $command, float $timeout) use (&$commands, $frameData): array {
            $commands[] = $command;
            $binary     = $command[0] ?? '';

            if (str_contains($binary, 'ffprobe')) {
                return [
                    'exitCode' => 0,
                    'stdout' => "5.0\n",
                    'stderr' => '',
                ];
            }

            if (str_contains($binary, 'ffmpeg')) {
                $targetPath = $command[array_key_last($command)];
                file_put_contents($targetPath, $frameData);

                return [
                    'exitCode' => 0,
                    'stdout' => '',
                    'stderr' => '',
                ];
            }

            return [
                'exitCode' => 1,
                'stdout' => '',
                'stderr' => 'unsupported',
            ];
        };

        $sampler   = new VideoFrameSampler('ffmpeg', 'ffprobe', 1.0, 12.0, $runner);
        $estimator = new DeterministicImageQualityEstimator($sampler);

        $stillPath = $this->createTemporaryFile('frame_', '.jpg');
        file_put_contents($stillPath, $frameData);

        $stillMedia = new Media($stillPath, sha1($frameData), (int) filesize($stillPath));
        $expected   = $estimator->scoreStill($stillMedia);

        $score = $estimator->scoreVideo($media);

        self::assertTrue($this->wasCommandInvoked($commands, 'ffprobe'));
        self::assertTrue($this->wasCommandInvoked($commands, 'ffmpeg'));

        self::assertEqualsWithDelta($expected->sharpness, $score->sharpness, 0.02);
       self::assertSame(0.0, $score->videoPenalty);
        self::assertEqualsWithDelta($expected->exposure, $score->exposure, 0.02);
        self::assertEqualsWithDelta($expected->contrast, $score->contrast, 0.02);
        self::assertEqualsWithDelta($expected->noise, $score->noise, 0.02);
        self::assertEqualsWithDelta($expected->blockiness, $score->blockiness, 0.02);
        self::assertEqualsWithDelta($expected->keyframeQuality, $score->keyframeQuality, 0.05);

        self::assertEqualsWithDelta(0.01, $score->videoBonus, 1e-6);

        unlink($videoPath);
        unlink($stillPath);
    }

    public function testScoreVideoFallsBackToNeutralWhenSamplerFails(): void
    {
        $videoPath = $this->createTemporaryFile('video_', '.mp4');
        file_put_contents($videoPath, 'stub');

        $media = $this->createVideoMedia($videoPath);
        $media->setVideoDurationS(1.0);
        $media->setVideoFps(50.0);

        $sampler = new class implements VideoFrameSamplerInterface {
            public function sampleLumaMatrix(Media $media, callable $loader): ?array
            {
                return null;
            }
        };

        $estimator = new DeterministicImageQualityEstimator($sampler);

        $score = $estimator->scoreVideo($media);

        self::assertEquals(0.5, $score->sharpness);
        self::assertEquals(0.5, $score->exposure);
        self::assertEquals(0.5, $score->contrast);
        self::assertEquals(0.5, $score->noise);
        self::assertEqualsWithDelta(0.5375, $score->blockiness, 1e-6);
        self::assertEqualsWithDelta(0.44, $score->keyframeQuality, 1e-6);
        self::assertEqualsWithDelta(0.25, $score->videoPenalty, 1e-6);
        self::assertEqualsWithDelta(0.0125, $score->videoBonus, 1e-6);

        unlink($videoPath);
    }

    private function createVideoMedia(string $path): Media
    {
        $media = new Media($path, sha1($path), 0);
        $media->setIsVideo(true);

        return $media;
    }

    private function createTemporaryFile(string $prefix, string $suffix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix);
        if ($temp === false) {
            self::fail('Unable to allocate temporary file.');
        }

        $path = $temp . $suffix;
        rename($temp, $path);

        return $path;
    }

    private function createSampleFrameBytes(): string
    {
        $image = imagecreatetruecolor(8, 8);
        if ($image === false) {
            self::fail('Unable to allocate test image.');
        }

        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $gray  = (int) (255 * (($x + $y) / 14));
                $color = imagecolorallocate($image, $gray, $gray, $gray);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        ob_start();
        imagejpeg($image, null, 90);
        $data = ob_get_clean();
        imagedestroy($image);

        if (!is_string($data)) {
            self::fail('Unable to capture frame bytes.');
        }

        return $data;
    }

    /**
     * @param list<array<int, string>> $commands
     */
    private function wasCommandInvoked(array $commands, string $needle): bool
    {
        foreach ($commands as $command) {
            $binary = $command[0] ?? '';
            if (str_contains($binary, $needle)) {
                return true;
            }
        }

        return false;
    }
}
