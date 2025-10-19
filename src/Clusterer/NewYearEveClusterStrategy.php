<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ContextualClusterBridgeTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function assert;
use function usort;

/**
 * Builds New Year's Eve clusters (local night around Dec 31 → Jan 1).
 */
final readonly class NewYearEveClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use ContextualClusterBridgeTrait;
    use MediaFilterTrait;
    use ClusterLocationMetadataTrait;
    use ProgressAwareClusterTrait;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        /** Hours considered NYE party window (local, 24h). */
        private int $startHour = 20,
        private int $endHour = 2,
        // Minimum media per year-long NYE bucket before emitting a memory.
        private int $minItemsPerYear = 6,
    ) {
        if ($this->startHour < 0 || $this->startHour > 23 || $this->endHour < 0 || $this->endHour > 23) {
            throw new InvalidArgumentException('Hour bounds must be within 0..23.');
        }

        if ($this->minItemsPerYear < 1) {
            throw new InvalidArgumentException('minItemsPerYear must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'new_year_eve';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<int, list<Media>> $byYear */
        $byYear = [];

        $nyeItems = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m): bool {
                $local = $this->localTimeHelper->resolve($m);
                assert($local instanceof DateTimeImmutable);
                $md   = $local->format('m-d');
                $hour = (int) $local->format('G');

                return ($md === '12-31' && $hour >= $this->startHour)
                    || ($md === '01-01' && $hour <= $this->endHour);
            }
        );

        foreach ($nyeItems as $m) {
            $local = $this->localTimeHelper->resolve($m);
            assert($local instanceof DateTimeImmutable);
            $y = (int) $local->format('Y');

            $byYear[$y] ??= [];
            $byYear[$y][] = $m;
        }

        $eligibleYears = $this->filterGroupsByMinItems($byYear, $this->minItemsPerYear);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleYears as $y => $list) {
            usort($list, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $params = $this->appendLocationMetadata($list, [
                'year'       => $y,
                'time_range' => $time,
            ]);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, Context $ctx, callable $update): array
    {
        return $this->runWithDefaultProgress(
            $items,
            $ctx,
            $update,
            fn (array $payload, Context $context): array => $this->draft($payload, $context)
        );
    }

}
