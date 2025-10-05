<?php 

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;

use function date_default_timezone_get;
use function filemtime;
use function is_file;
use function is_int;
use function sprintf;

/**
 * Fills missing capture timestamps using file system metadata.
 */
final class FileStatMetadataExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return $media->getTakenAt() === null && is_file($filepath);
    }

    public function extract(string $filepath, Media $media): Media
    {
        $timestamp = @filemtime($filepath);
        if (!is_int($timestamp)) {
            return $media;
        }

        $timezoneName = date_default_timezone_get();
        $timezone     = new DateTimeZone($timezoneName);

        $takenAt = (new DateTimeImmutable(sprintf('@%d', $timestamp)))->setTimezone($timezone);

        $media->setTakenAt($takenAt);
        $media->setCapturedLocal($takenAt);
        $media->setTzId($timezoneName);
        $media->setTimeSource(TimeSource::FILE_MTIME);
        $media->setTzConfidence(0.2);

        return $media;
    }
}
