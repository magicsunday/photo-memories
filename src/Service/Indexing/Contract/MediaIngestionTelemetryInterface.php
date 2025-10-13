<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Contract;

use MagicSunday\Memories\Entity\Media;

/**
 * Aggregates telemetry across media ingestion pipeline runs.
 */
interface MediaIngestionTelemetryInterface
{
    public function recordProcessedMedia(string $filepath, Media $media): void;

    public function recordQuickTimeTimezoneHit(string $filepath): void;

    public function recordXmpTimezoneHit(string $filepath): void;

    public function recordFfprobeAvailability(string $filepath, bool $available): void;

    /**
     * @return array{
     *     images_total:int,
     *     videos_total:int,
     *     faces_detected:int,
     *     exif_tz_hits:int,
     *     quicktime_timezone_hits?:int,
     *     xmp_timezone_hits?:int,
     *     ffprobe_available:int,
     *     ffprobe_missing:int
     * }
     */
    public function metrics(): array;

    public function reset(): void;
}
