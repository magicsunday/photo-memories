<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Collects videos into day-based stories (local time).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 41])]
final class VideoStoriesClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 2
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'video_stories';
    }

    /**
     * @param list<Media> $members
     */
    protected function minimumGroupSize(string $key, array $members): int
    {
        return $this->minItems;
    }

    protected function shouldConsider(Media $media): bool
    {
        $mime = $media->getMime();

        return !\is_string($mime) || \str_starts_with($mime, 'video/');
    }

    protected function localGroupKey(Media $media, DateTimeImmutable $local): ?string
    {
        return $local->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        return [];
    }
}
