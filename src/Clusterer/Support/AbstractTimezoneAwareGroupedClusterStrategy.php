<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;

/**
 * Adds reusable timezone handling helpers for grouped cluster strategies that
 * need to work with local timestamps.
 */
abstract class AbstractTimezoneAwareGroupedClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(string $timezone = 'Europe/Berlin')
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    final protected function groupKey(Media $media): ?string
    {
        $local = $this->localTakenAt($media);
        if ($local === null) {
            return null;
        }

        return $this->localGroupKey($media, $local);
    }

    abstract protected function localGroupKey(Media $media, DateTimeImmutable $local): ?string;

    protected function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    protected function takenAt(Media $media): ?DateTimeImmutable
    {
        $takenAt = $media->getTakenAt();

        return $takenAt instanceof DateTimeImmutable ? $takenAt : null;
    }

    protected function localTakenAt(Media $media): ?DateTimeImmutable
    {
        $takenAt = $this->takenAt($media);

        return $takenAt?->setTimezone($this->timezone);
    }

    /**
     * @param list<Media> $members
     * @return array<string, bool>
     */
    protected function uniqueLocalDateParts(array $members, string $format): array
    {
        return $this->uniqueDateParts($members, $format, $this->timezone);
    }
}
