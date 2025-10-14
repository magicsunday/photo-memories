<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Support;

use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionTelemetryInterface;

use function array_key_exists;
use function is_string;
use function strtolower;
use function str_starts_with;

/**
 * Collects aggregated telemetry for media ingestion runs.
 */
final class MediaIngestionTelemetryCollector implements MediaIngestionTelemetryInterface
{
    /**
     * @var array<string, array{isVideo: bool, isImage: bool, facesDetected: bool}>
     */
    private array $mediaRecords = [];

    /**
     * @var array<string, bool>
     */
    private array $ffprobeAvailability = [];

    /**
     * @var array<string, true>
     */
    private array $quickTimeTimezone = [];

    /**
     * @var array<string, true>
     */
    private array $xmpTimezone = [];

    private bool $ffprobeBinaryMissing = false;

    public function recordProcessedMedia(string $filepath, Media $media): void
    {
        $mime = $media->getMime();
        $normalisedMime = is_string($mime) ? strtolower($mime) : null;

        $isVideo = $media->isVideo()
            || ($normalisedMime !== null && str_starts_with($normalisedMime, 'video/'));
        $isImage = !$isVideo
            && $normalisedMime !== null
            && str_starts_with($normalisedMime, 'image/');

        $facesDetected = ($media->hasFaces() === true)
            || ($media->getFacesCount() !== null && $media->getFacesCount() > 0);

        $this->mediaRecords[$filepath] = [
            'isVideo'        => $isVideo,
            'isImage'        => $isImage,
            'facesDetected'  => $facesDetected,
        ];

        if ($isVideo && array_key_exists($filepath, $this->ffprobeAvailability) === false) {
            $hasVideoMetadata = $media->getVideoStreams() !== null
                || $media->getVideoCodec() !== null
                || $media->getVideoDurationS() !== null
                || $media->getVideoFps() !== null;

            $this->ffprobeAvailability[$filepath] = $hasVideoMetadata;
        }

        if ($media->getTimeSource() === TimeSource::VIDEO_QUICKTIME) {
            $this->quickTimeTimezone[$filepath] = true;
        }
    }

    public function recordQuickTimeTimezoneHit(string $filepath): void
    {
        $this->quickTimeTimezone[$filepath] = true;
    }

    public function recordXmpTimezoneHit(string $filepath): void
    {
        $this->xmpTimezone[$filepath] = true;
    }

    public function recordFfprobeAvailability(string $filepath, bool $available): void
    {
        if ($available) {
            $this->ffprobeAvailability[$filepath] = true;

            return;
        }

        if (array_key_exists($filepath, $this->ffprobeAvailability) === false) {
            $this->ffprobeAvailability[$filepath] = false;
        }
    }

    public function recordFfprobeBinaryMissing(): void
    {
        $this->ffprobeBinaryMissing = true;
    }

    public function metrics(): array
    {
        $imagesTotal  = 0;
        $videosTotal  = 0;
        $faces        = 0;
        $ffprobeAvail = 0;
        $ffprobeMiss  = 0;

        foreach ($this->mediaRecords as $path => $record) {
            if ($record['isVideo']) {
                ++$videosTotal;
                if (array_key_exists($path, $this->ffprobeAvailability) === false) {
                    $this->ffprobeAvailability[$path] = false;
                }
            } elseif ($record['isImage']) {
                ++$imagesTotal;
            }

            if ($record['facesDetected']) {
                ++$faces;
            }
        }

        foreach ($this->ffprobeAvailability as $available) {
            if ($available) {
                ++$ffprobeAvail;
            } else {
                ++$ffprobeMiss;
            }
        }

        $combinedExifHits = $this->quickTimeTimezone + $this->xmpTimezone;

        return [
            'images_total'            => $imagesTotal,
            'videos_total'            => $videosTotal,
            'faces_detected'          => $faces,
            'exif_tz_hits'            => count($combinedExifHits),
            'quicktime_timezone_hits' => count($this->quickTimeTimezone),
            'xmp_timezone_hits'       => count($this->xmpTimezone),
            'ffprobe_ok'              => $ffprobeAvail,
            'ffprobe_missing'         => $ffprobeMiss,
            'ffprobe_binary_missing'  => $this->ffprobeBinaryMissing,
        ];
    }

    public function reset(): void
    {
        $this->mediaRecords       = [];
        $this->ffprobeAvailability = [];
        $this->quickTimeTimezone  = [];
        $this->xmpTimezone        = [];
        $this->ffprobeBinaryMissing = false;
    }
}
