<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Service\Indexing;

use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\DefaultMediaIngestionPipeline;
use MagicSunday\Memories\Service\Indexing\Support\MediaIngestionTelemetryCollector;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Output\NullOutput;

use function array_filter;
use function array_key_first;
use function count;
use function str_ends_with;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class MediaIngestionTelemetryIntegrationTest extends TestCase
{
    #[Test]
    public function itLogsAggregatedMetricsDuringFinalize(): void
    {
        $telemetry = new MediaIngestionTelemetryCollector();
        $logger    = new ArrayLogger();

        $pipeline = new DefaultMediaIngestionPipeline([
            new TelemetryFixtureStage($telemetry),
        ], $telemetry, $logger);

        $imagePath = $this->createFixture('jpg');
        $videoPath = $this->createFixture('mov');

        try {
            $pipeline->process($imagePath, false, false, false, false, new NullOutput());
            $pipeline->process($videoPath, false, false, false, false, new NullOutput());
        } finally {
            @unlink($imagePath);
            @unlink($videoPath);
        }

        $pipeline->finalize(false);

        $records = array_filter(
            $logger->records,
            static fn (array $record): bool => ($record['message'] ?? '') === 'media_ingestion.finalize'
        );

        self::assertSame(1, count($records));

        $entry   = $records[array_key_first($records)];
        $context = $entry['context'] ?? [];

        self::assertSame(false, $context['dryRun'] ?? null);

        $metrics = $context['metrics'] ?? [];
        self::assertSame(1, $metrics['images_total'] ?? null);
        self::assertSame(1, $metrics['videos_total'] ?? null);
        self::assertSame(1, $metrics['faces_detected'] ?? null);
        self::assertSame(2, $metrics['exif_tz_hits'] ?? null);
        self::assertSame(1, $metrics['quicktime_timezone_hits'] ?? null);
        self::assertSame(1, $metrics['xmp_timezone_hits'] ?? null);
        self::assertSame(1, $metrics['ffprobe_available'] ?? null);
        self::assertSame(0, $metrics['ffprobe_missing'] ?? null);
    }

    private function createFixture(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingest-');
        if ($path === false) {
            self::fail('Unable to create temporary fixture.');
        }

        $target = $path . '.' . $extension;
        if (@rename($path, $target) === false) {
            @unlink($path);
            self::fail('Unable to rename fixture.');
        }

        if (@file_put_contents($target, str_repeat('x', 8)) === false) {
            @unlink($target);
            self::fail('Unable to seed fixture content.');
        }

        return $target;
    }
}

final class TelemetryFixtureStage implements MediaIngestionStageInterface
{
    public function __construct(private readonly MediaIngestionTelemetryCollector $telemetry)
    {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        $media = new Media($context->getFilePath(), str_repeat('a', 64), 1024);
        $media->setMime($this->resolveMime($context->getFilePath()));

        if (str_ends_with($context->getFilePath(), '.jpg')) {
            $media->setHasFaces(true);
            $media->setFacesCount(1);
            $this->telemetry->recordXmpTimezoneHit($context->getFilePath());
        } else {
            $media->setIsVideo(true);
            $media->setVideoStreams([['codec_name' => 'h264']]);
            $media->setTimeSource(TimeSource::VIDEO_QUICKTIME);
            $this->telemetry->recordQuickTimeTimezoneHit($context->getFilePath());
            $this->telemetry->recordFfprobeAvailability($context->getFilePath(), true);
        }

        return $context->withMedia($media);
    }

    private function resolveMime(string $filepath): string
    {
        return str_ends_with($filepath, '.jpg') ? 'image/jpeg' : 'video/quicktime';
    }
}

final class ArrayLogger extends AbstractLogger
{
    /** @var array<int, array{level:string,message:string,context:array}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
