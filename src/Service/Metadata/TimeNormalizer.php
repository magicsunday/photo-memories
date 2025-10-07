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

use function abs;
use function filemtime;
use function floor;
use function intdiv;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Normalises capture timestamps and timezone metadata based on priority sources.
 */
final readonly class TimeNormalizer implements SingleMetadataExtractorInterface
{
    private const FALLBACK_FILENAME   = 'filename';
    private const FALLBACK_FILE_MTIME = 'file_mtime';

    /**
     * @var list<string>
     */
    private array $fallbackPriority;

    public function __construct(
        private CaptureTimeResolver $captureTimeResolver,
        private string $defaultTimezone,
        private FilenameDateParser $filenameDateParser,
        array $sourcePriority = [],
        private int $plausibilityThresholdMinutes = 720,
    ) {
        $this->fallbackPriority = $this->normaliseSourcePriority($sourcePriority);
    }

    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $this->applyPrioritisedTakenAt($filepath, $media);
        $this->normaliseTimezone($media);
        $this->validateAgainstFilesystem($filepath, $media);
        $this->appendSummaryLog($media);

        return $media;
    }

    private function applyPrioritisedTakenAt(string $filepath, Media $media): void
    {
        $source  = $media->getTimeSource();
        $takenAt = $media->getTakenAt();

        if ($source === TimeSource::EXIF || ($source === TimeSource::VIDEO_QUICKTIME && $takenAt instanceof DateTimeImmutable)) {
            return;
        }

        if ($takenAt instanceof DateTimeImmutable && $source !== null && $source !== TimeSource::FILE_MTIME) {
            return;
        }

        foreach ($this->fallbackPriority as $candidate) {
            $before = $media->getTimeSource();

            if ($candidate === self::FALLBACK_FILENAME) {
                $this->applyFilenameFallback($filepath, $media);
            }

            if ($candidate === self::FALLBACK_FILE_MTIME) {
                $this->applyFilesystemFallback($filepath, $media);
            }

            $resolvedSource = $media->getTimeSource();
            $expected       = $this->candidateToTimeSource($candidate);

            if ($resolvedSource === $expected && $media->getTakenAt() instanceof DateTimeImmutable) {
                break;
            }

            if ($before === $resolvedSource) {
                continue;
            }
        }
    }

    private function applyFilenameFallback(string $filepath, Media $media): void
    {
        $source  = $media->getTimeSource();
        $takenAt = $media->getTakenAt();

        if ($source === TimeSource::FILENAME) {
            return;
        }

        if ($takenAt instanceof DateTimeImmutable && $source !== null && $source !== TimeSource::FILE_MTIME) {
            return;
        }

        $parsed = $this->filenameDateParser->parse($filepath, $this->defaultTimezone());
        if (!$parsed instanceof DateTimeImmutable) {
            return;
        }

        $media->setTakenAt($parsed);
        $media->setCapturedLocal($parsed);
        $media->setTimeSource(TimeSource::FILENAME);
        $media->setTzId($parsed->getTimezone()->getName());
        $this->promoteTzConfidence($media, 0.4);
    }

    private function applyFilesystemFallback(string $filepath, Media $media): void
    {
        $source = $media->getTimeSource();

        if ($source === TimeSource::FILENAME) {
            return;
        }

        if ($media->getTakenAt() instanceof DateTimeImmutable && $source !== null && $source !== TimeSource::FILE_MTIME) {
            return;
        }

        $fileInstant = $this->fileModificationInstant($filepath);
        if (!$fileInstant instanceof DateTimeImmutable) {
            return;
        }

        $media->setTakenAt($fileInstant);
        $media->setCapturedLocal($fileInstant);
        $media->setTzId($fileInstant->getTimezone()->getName());
        $media->setTimeSource(TimeSource::FILE_MTIME);
        $this->promoteTzConfidence($media, 0.2);
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

    /**
     * @param array<string|int> $priority
     *
     * @return list<string>
     */
    private function normaliseSourcePriority(array $priority): array
    {
        $normalised = [];

        foreach ($priority as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $token = $this->mapPriorityToken($entry);
            if ($token !== null) {
                $normalised[$token] = true;
            }
        }

        if ($normalised === []) {
            $normalised = [self::FALLBACK_FILENAME => true, self::FALLBACK_FILE_MTIME => true];
        }

        return array_keys($normalised);
    }

    private function mapPriorityToken(string $value): ?string
    {
        $value = strtolower($value);

        return match ($value) {
            'filename', strtolower(TimeSource::FILENAME->value) => self::FALLBACK_FILENAME,
            'file_mtime', 'filemtime', strtolower(TimeSource::FILE_MTIME->value) => self::FALLBACK_FILE_MTIME,
            default => null,
        };
    }

    private function validateAgainstFilesystem(string $filepath, Media $media): void
    {
        if ($this->plausibilityThresholdMinutes <= 0) {
            return;
        }

        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return;
        }

        $fileInstant = $this->fileModificationInstant($filepath);
        if (!$fileInstant instanceof DateTimeImmutable) {
            return;
        }

        $deltaSeconds = abs($takenAt->getTimestamp() - $fileInstant->getTimestamp());
        $deltaMinutes = (int) floor($deltaSeconds / 60);

        if ($deltaMinutes <= $this->plausibilityThresholdMinutes) {
            return;
        }

        $message = sprintf(
            'Warnung: Aufnahmezeit weicht vom Dateisystem um %d Minuten ab.',
            $deltaMinutes,
        );

        IndexLogHelper::append($media, $message);
    }

    private function candidateToTimeSource(string $candidate): ?TimeSource
    {
        return match ($candidate) {
            self::FALLBACK_FILENAME => TimeSource::FILENAME,
            self::FALLBACK_FILE_MTIME => TimeSource::FILE_MTIME,
            default => null,
        };
    }
}
