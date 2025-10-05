<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Exif\Processor;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Exif\Contract\ExifMetadataProcessorInterface;
use MagicSunday\Memories\Service\Metadata\Exif\Contract\ExifValueAccessorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use function abs;
use function intdiv;
use function sprintf;

/**
 * Applies capture date, timezone offset and sub-second precision from EXIF data.
 */
#[AutoconfigureTag('memories.metadata.exif.processor')]
final readonly class DateTimeExifMetadataProcessor implements ExifMetadataProcessorInterface
{
    public function __construct(
        private ExifValueAccessorInterface $accessor,
    ) {
    }

    public function process(array $exif, Media $media): void
    {
        $takenAt = $this->accessor->findDate($exif);
        $offset  = $this->accessor->parseOffsetMinutes($exif);

        $timezone = null;

        if ($takenAt !== null) {
            if ($offset !== null) {
                $absOffset = abs($offset);
                $timezone = new DateTimeZone(sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv($absOffset, 60), $absOffset % 60));
                $takenAt  = new DateTimeImmutable($takenAt->format('Y-m-d H:i:s'), $timezone);
            } else {
                $timezone = $takenAt->getTimezone();
            }

            $media->setTakenAt($takenAt);
            $media->setCapturedLocal($takenAt);
            $media->setTimeSource(TimeSource::EXIF);

            if ($timezone instanceof DateTimeZone && ($offset !== null || $media->getTzId() === null)) {
                $media->setTzId($timezone->getName());
            }
        }

        if ($offset !== null) {
            $media->setTimezoneOffsetMin($offset);
            $media->setTzConfidence(1.0);
        }

        $subSeconds = $this->accessor->intOrNull($exif['EXIF']['SubSecTimeOriginal'] ?? null);

        if ($subSeconds !== null) {
            $media->setSubSecOriginal($subSeconds);
        }
    }
}
