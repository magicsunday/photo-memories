<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Collects videos into day-based stories (local time).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 41])]
final class VideoStoriesClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItems = 2
    ) {
    }

    public function name(): string
    {
        return 'video_stories';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($items as $m) {
            $mime = $m->getMime();
            if (!\is_string($mime) || \strpos($mime, 'video/') !== 0) {
                continue;
            }
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($byDay as $day => $members) {
            if (\count($members) < $this->minItems) {
                continue;
            }
            \usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );
        }

        return $out;
    }
}
