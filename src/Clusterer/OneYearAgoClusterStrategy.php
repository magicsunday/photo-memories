<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Builds a memory around the same date last year within a +/- window.
 */
final class OneYearAgoClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $windowDays = 3,
        private readonly int $minItemsTotal   = 8
    ) {
        if ($this->windowDays < 0) {
            throw new \InvalidArgumentException('windowDays must be >= 0.');
        }
        if ($this->minItemsTotal < 1) {
            throw new \InvalidArgumentException('minItemsTotal must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'one_year_ago';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);
        $now = new DateTimeImmutable('now', $tz);
        $anchorStart = $now->sub(new DateInterval('P1Y'))->modify('-' . $this->windowDays . ' days');
        $anchorEnd   = $now->sub(new DateInterval('P1Y'))->modify('+' . $this->windowDays . ' days');

        /** @var list<Media> $picked */
        $picked = $this->filterTimestampedItemsBy(
            $items,
            static function (Media $m) use ($tz, $anchorStart, $anchorEnd): bool {
                $takenAt = $m->getTakenAt();
                \assert($takenAt instanceof DateTimeImmutable);

                $local = $takenAt->setTimezone($tz);

                return $local >= $anchorStart && $local <= $anchorEnd;
            }
        );

        if (\count($picked) < $this->minItemsTotal) {
            return [];
        }

        \usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
        $centroid = MediaMath::centroid($picked);
        $time     = MediaMath::timeRange($picked);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $picked)
            ),
        ];
    }
}
