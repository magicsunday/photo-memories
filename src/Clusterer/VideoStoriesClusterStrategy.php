<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Collects videos into day-based stories (local time).
 */
final readonly class VideoStoriesClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private string $timezone = 'Europe/Berlin',
        // Minimum number of videos per local day to emit a story.
        private int $minItemsPerDay = 2
    ) {
        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
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

        $videoItems = $this->filterTimestampedItemsBy(
            $items,
            static function (Media $m): bool {
                $mime = $m->getMime();

                return \is_string($mime) && \str_starts_with($mime, 'video/');
            }
        );

        foreach ($videoItems as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleDays */
        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleDays as $members) {
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
