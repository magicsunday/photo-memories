<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use DateTimeImmutable;
use DateTimeInterface;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\FfprobeMetadataExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function in_array;
use function file_get_contents;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class FfprobeMetadataExtractorTest extends TestCase
{
    #[Test]
    public function extractsRotationFromTagAndDetectsStabilisation(): void
    {
        $fixture   = $this->loadFixture('rotate-stabilised.json');
        $extractor = new FfprobeMetadataExtractor(processRunner: static fn (array $command, float $timeout): string => $fixture);

        $videoPath = tempnam(sys_get_temp_dir(), 'vid');
        if ($videoPath === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        try {
            $media = new Media($videoPath, str_repeat('a', 64), 2048);
            $media->setMime('video/mp4');

            $extractor->extract($videoPath, $media);

            self::assertSame('h264', $media->getVideoCodec());
            self::assertSame(120.0, $media->getVideoFps());
            self::assertSame(12.345, $media->getVideoDurationS());
            self::assertTrue($media->isSlowMo());
            self::assertSame(90.0, $media->getVideoRotationDeg());
            self::assertTrue($media->getVideoHasStabilization());
            self::assertFalse($media->isHevc());

            $streams = $media->getVideoStreams();
            self::assertIsArray($streams);
            self::assertCount(1, $streams);
            self::assertSame(
                [
                    [
                        'index'          => 0,
                        'codec_name'     => 'h264',
                        'codec_type'     => 'video',
                        'avg_frame_rate' => '120/1',
                        'tags'           => ['rotate' => '90'],
                        'side_data_list' => [
                            [
                                'side_data_type' => 'Display Matrix',
                                'rotation'       => '90',
                            ],
                            [
                                'side_data_type' => 'Camera Motion',
                                'stabilization'  => 'on',
                            ],
                        ],
                    ],
                ],
                $streams
            );
        } finally {
            unlink($videoPath);
        }
    }

    #[Test]
    public function extractsDisplayMatrixRotationWithoutStabilisation(): void
    {
        $fixture   = $this->loadFixture('displaymatrix-rotation.json');
        $extractor = new FfprobeMetadataExtractor(processRunner: static fn (array $command, float $timeout): string => $fixture);

        $videoPath = tempnam(sys_get_temp_dir(), 'vid');
        if ($videoPath === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        try {
            $media = new Media($videoPath, str_repeat('b', 64), 1024);
            $media->setMime('video/mp4');

            $extractor->extract($videoPath, $media);

            self::assertSame('hevc', $media->getVideoCodec());
            self::assertSame(30.0, $media->getVideoFps());
            self::assertSame(5.0, $media->getVideoDurationS());
            self::assertFalse($media->isSlowMo());
            self::assertSame(-90.0, $media->getVideoRotationDeg());
            self::assertNull($media->getVideoHasStabilization());
            self::assertTrue($media->isHevc());

            $streams = $media->getVideoStreams();
            self::assertIsArray($streams);
            self::assertCount(1, $streams);
            self::assertSame(
                [
                    [
                        'index'          => 0,
                        'codec_name'     => 'hevc',
                        'codec_type'     => 'video',
                        'avg_frame_rate' => '30/1',
                        'side_data_list' => [
                            [
                                'side_data_type' => 'Display Matrix',
                                'displaymatrix'  => [
                                    'rotation' => '-90',
                                ],
                            ],
                        ],
                    ],
                ],
                $streams
            );
        } finally {
            unlink($videoPath);
        }
    }

    #[Test]
    public function appliesQuickTimeFallbackMetadata(): void
    {
        $streamFixture    = $this->loadFixture('displaymatrix-rotation.json');
        $quickTimeFixture = $this->loadFixture('quicktime-metadata.json');

        $runner = static function (array $command, float $timeout) use ($streamFixture, $quickTimeFixture): string {
            if (in_array('stream=codec_name,avg_frame_rate,side_data_list:stream_tags=rotate:format=duration', $command, true)) {
                return $streamFixture;
            }

            return $quickTimeFixture;
        };

        $videoPath = tempnam(sys_get_temp_dir(), 'vid');
        if ($videoPath === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        try {
            $media = new Media($videoPath, str_repeat('c', 64), 4096);
            $media->setMime('video/quicktime');

            $extractor = new FfprobeMetadataExtractor(processRunner: $runner);
            $extractor->extract($videoPath, $media);

            self::assertSame(TimeSource::VIDEO_QUICKTIME, $media->getTimeSource());
            $takenAt = $media->getTakenAt();
            self::assertInstanceOf(DateTimeImmutable::class, $takenAt);
            self::assertSame('2025-01-01T04:59:58+00:00', $takenAt?->format(DateTimeInterface::ATOM));
            self::assertSame('America/New_York', $media->getTzId());
            self::assertSame(-300, $media->getTimezoneOffsetMin());
        } finally {
            unlink($videoPath);
        }
    }

    #[Test]
    public function logsProcessFailuresAndSkipsMetadata(): void
    {
        $runner = static function (array $command, float $timeout): array {
            return [
                'exitCode' => 1,
                'stdout'   => '',
                'stderr'   => 'failed',
            ];
        };

        $videoPath = tempnam(sys_get_temp_dir(), 'vid');
        if ($videoPath === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        try {
            $media = new Media($videoPath, str_repeat('d', 64), 4096);
            $media->setMime('video/mp4');

            $extractor = new FfprobeMetadataExtractor(processRunner: $runner);
            $extractor->extract($videoPath, $media);

            self::assertNull($media->getVideoCodec());
            self::assertNull($media->getTakenAt());
            $entries = $this->decodeIndexLog($media->getIndexLog());
            self::assertNotSame([], $entries);
            self::assertStringContainsString('ffprobe exited with 1', (string) $entries[0]['message']);
            self::assertSame('metadata.ffprobe', $entries[0]['component']);
        } finally {
            unlink($videoPath);
        }
    }

    private function loadFixture(string $filename): string
    {
        $path     = __DIR__ . '/fixtures/ffprobe/' . $filename;
        $contents = file_get_contents($path);
        if ($contents === false) {
            self::fail('Unable to load ffprobe fixture: ' . $filename);
        }

        return $contents;
    }
}
