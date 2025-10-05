<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function assert;
use function is_string;
use function str_starts_with;
use function usort;

/**
 * Collects videos into day-based stories (local time).
 */
final readonly class VideoStoriesClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        // Minimum number of videos per local day to emit a story.
        private int $minItemsPerDay = 2,
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
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        $videoItems = $this->filterTimestampedItemsBy(
            $items,
            static function (Media $m): bool {
                $mime = $m->getMime();

                return is_string($mime) && str_starts_with($mime, 'video/');
            }
        );

        foreach ($videoItems as $m) {
            $local = $this->localTimeHelper->resolve($m);
            assert($local instanceof DateTimeImmutable);
            $key   = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleDays */
        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleDays as $members) {
            usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $videoCount            = count($members);
            $videoDurationTotal    = 0.0;
            $videoSlowMoCount      = 0;
            $videoStabilizedCount  = 0;

            foreach ($members as $member) {
                $videoDuration = $member->getVideoDurationS();
                if ($videoDuration !== null) {
                    $videoDurationTotal += $videoDuration;
                }

                $isSlowMo = $member->isSlowMo();
                if ($isSlowMo === true) {
                    ++$videoSlowMoCount;
                }

                $hasStabilization = $member->getVideoHasStabilization();
                if ($hasStabilization === true) {
                    ++$videoStabilizedCount;
                }
            }

            $params = [
                'time_range' => $time,
            ];

            if ($videoCount > 0) {
                $params['video_count'] = $videoCount;
            }

            if ($videoDurationTotal > 0.0) {
                $params['video_duration_total_s'] = $videoDurationTotal;
            }

            if ($videoSlowMoCount > 0) {
                $params['video_slow_mo_count'] = $videoSlowMoCount;
            }

            if ($videoStabilizedCount > 0) {
                $params['video_stabilized_count'] = $videoStabilizedCount;
            }

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $members)
            );
        }

        return $out;
    }
}
