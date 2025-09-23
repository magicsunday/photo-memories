<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Collects videos into day-based stories (local time).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 41])]
final class VideoStoriesClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 2
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function name(): string
    {
        return 'video_stories';
    }

    protected function shouldConsider(Media $media): bool
    {
        $mime = $media->getMime();

        return !\is_string($mime) || \str_starts_with($mime, 'video/');
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        return $takenAt->setTimezone($this->timezone)->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        return [];
    }
}
