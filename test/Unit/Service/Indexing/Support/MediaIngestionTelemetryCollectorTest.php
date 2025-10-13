<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing\Support;

use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Support\MediaIngestionTelemetryCollector;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MediaIngestionTelemetryCollectorTest extends TestCase
{
    #[Test]
    public function itAggregatesAndResetsMetrics(): void
    {
        $collector = new MediaIngestionTelemetryCollector();

        $image = $this->makeMedia(
            id: 10,
            path: '/fixtures/image.jpg',
            configure: static function (Media $media): void {
                $media->setMime('image/jpeg');
                $media->setHasFaces(true);
                $media->setFacesCount(2);
            },
        );

        $video = $this->makeMedia(
            id: 11,
            path: '/fixtures/video.mov',
            configure: static function (Media $media): void {
                $media->setMime('video/quicktime');
                $media->setIsVideo(true);
                $media->setVideoStreams([['codec_name' => 'h264']]);
                $media->setTimeSource(TimeSource::VIDEO_QUICKTIME);
            },
        );

        $collector->recordProcessedMedia('/fixtures/image.jpg', $image);
        $collector->recordProcessedMedia('/fixtures/video.mov', $video);
        $collector->recordFfprobeAvailability('/fixtures/video.mov', true);
        $collector->recordXmpTimezoneHit('/fixtures/image.jpg');

        $metrics = $collector->metrics();

        self::assertSame(1, $metrics['images_total']);
        self::assertSame(1, $metrics['videos_total']);
        self::assertSame(1, $metrics['faces_detected']);
        self::assertSame(2, $metrics['exif_tz_hits']);
        self::assertSame(1, $metrics['quicktime_timezone_hits']);
        self::assertSame(1, $metrics['xmp_timezone_hits']);
        self::assertSame(1, $metrics['ffprobe_available']);
        self::assertSame(0, $metrics['ffprobe_missing']);

        $collector->reset();

        $cleared = $collector->metrics();
        self::assertSame(0, $cleared['images_total']);
        self::assertSame(0, $cleared['videos_total']);
        self::assertSame(0, $cleared['faces_detected']);
        self::assertSame(0, $cleared['exif_tz_hits']);
        self::assertSame(0, $cleared['quicktime_timezone_hits']);
        self::assertSame(0, $cleared['xmp_timezone_hits']);
        self::assertSame(0, $cleared['ffprobe_available']);
        self::assertSame(0, $cleared['ffprobe_missing']);
    }
}
