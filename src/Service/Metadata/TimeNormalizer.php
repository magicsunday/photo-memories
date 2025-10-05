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
use Exception;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Service\Metadata\Support\FilenameDateParser;
use MagicSunday\Memories\Support\IndexLogHelper;

use function filemtime;
use function intdiv;
use function is_int;
use function sprintf;

/**
 * Normalises capture timestamps and timezone metadata based on priority sources.
 */
final readonly class TimeNormalizer implements SingleMetadataExtractorInterface
{
    public function __construct(
        private CaptureTimeResolver $captureTimeResolver,
        private string              $defaultTimezone,
        private FilenameDateParser  $filenameDateParser,
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $this->applyPrioritisedTakenAt($filepath, $media);
        $this->normaliseTimezone($media);
        $this->appendSummaryLog($media);

        return $media;
    }

    private function applyPrioritisedTakenAt(string $filepath, Media $media): void
    {
        $source  = $media->getTimeSource();
        $takenAt = $media->getTakenAt();

        if ($source === TimeSource::EXIF) {
            return;
        }

        if ($source === TimeSource::VIDEO_QUICKTIME && $takenAt instanceof DateTimeImmutable) {
            return;
        }

        if ($source === null || $source === TimeSource::FILE_MTIME || !$takenAt instanceof DateTimeImmutable) {
            $parsed = $this->filenameDateParser->parse($filepath, $this->defaultTimezone());
            if ($parsed instanceof DateTimeImmutable) {
                $media->setTakenAt($parsed);
                $media->setCapturedLocal($parsed);
                $media->setTimeSource(TimeSource::FILENAME);
                $media->setTzId($parsed->getTimezone()->getName());
                $this->promoteTzConfidence($media, 0.4);

                return;
            }
        }

        if (!$takenAt instanceof DateTimeImmutable) {
            $fileInstant = $this->fileModificationInstant($filepath);
            if ($fileInstant instanceof DateTimeImmutable) {
                $media->setTakenAt($fileInstant);
                $media->setCapturedLocal($fileInstant);
                $media->setTzId($fileInstant->getTimezone()->getName());
                $media->setTimeSource(TimeSource::FILE_MTIME);
                $this->promoteTzConfidence($media, 0.2);
            }
        }
    }

    private function fileModificationInstant(string $filepath): ?DateTimeImmutable
    {
        $timestamp = @filemtime($filepath);
        if (!is_int($timestamp)) {
            return null;
        }

        try {
            return (new DateTimeImmutable(sprintf('@%d', $timestamp)))->setTimezone($this->defaultTimezone());
        } catch (Exception) {
            return null;
        }
    }

    private function normaliseTimezone(Media $media): void
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return;
        }

        $local = $this->captureTimeResolver->resolve($media);
        if (!$local instanceof DateTimeImmutable) {
            $timezone = $this->defaultTimezone();
            $local    = $takenAt->setTimezone($timezone);
            $media->setCapturedLocal($local);
            if ($media->getTzId() === null) {
                $media->setTzId($timezone->getName());
            }
            $this->promoteTzConfidence($media, 0.2);
        }

        if ($media->getTimezoneOffsetMin() === null) {
            $media->setTimezoneOffsetMin(intdiv($local->getOffset(), 60));
        }
    }

    private function appendSummaryLog(Media $media): void
    {
        $source = $media->getTimeSource();
        $offset = $media->getTimezoneOffsetMin();
        $tzId   = $media->getTzId() ?? $this->defaultTimezone()->getName();

        $summary = sprintf(
            'time=%s; tz=%s; off=%s',
            $source instanceof TimeSource ? $source->value : 'none',
            $tzId,
            $offset !== null ? sprintf('%+d', $offset) : 'n/a',
        );

        IndexLogHelper::append($media, $summary);
    }

    private function defaultTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->defaultTimezone);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }

    private function promoteTzConfidence(Media $media, float $confidence): void
    {
        $current = $media->getTzConfidence();

        if ($current === null || $confidence > $current) {
            $media->setTzConfidence($confidence);
        }
    }
}
