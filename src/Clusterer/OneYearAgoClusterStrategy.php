<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInterval;
use DateInvalidOperationException;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function assert;
use function count;
use function usort;

/**
 * Builds a memory around the same date last year within a +/- window.
 */
final readonly class OneYearAgoClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;

    private LocationHelper $locationHelper;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        LocationHelper $locationHelper,
        private string $timezone = 'Europe/Berlin',
        private int $windowDays = 3,
        private int $minItemsTotal = 8,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->windowDays < 0) {
            throw new InvalidArgumentException('windowDays must be >= 0.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }

        $this->locationHelper    = $locationHelper;
        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'one_year_ago';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     *
     * @throws DateInvalidOperationException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    public function cluster(array $items): array
    {
        $tz          = new DateTimeZone($this->timezone);
        $now         = new DateTimeImmutable('now', $tz);
        $anchorStart = $now->sub(new DateInterval('P1Y'))->modify('-' . $this->windowDays . ' days');
        $anchorEnd   = $now->sub(new DateInterval('P1Y'))->modify('+' . $this->windowDays . ' days');

        /** @var list<Media> $picked */
        $picked = $this->filterTimestampedItemsBy(
            $items,
            static function (Media $m) use ($tz, $anchorStart, $anchorEnd): bool {
                $takenAt = $m->getTakenAt();
                assert($takenAt instanceof DateTimeImmutable);

                $local = $takenAt->setTimezone($tz);

                return $local >= $anchorStart && $local <= $anchorEnd;
            }
        );

        if (count($picked) < $this->minItemsTotal) {
            return [];
        }

        usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
        $centroid = $this->computeCentroid($picked);
        $time     = $this->computeTimeRange($picked);

        $params = [
            'time_range' => $time,
        ];

        $tags = $this->collectDominantTags($picked);
        if ($tags !== []) {
            $params = [...$params, ...$tags];
        }

        $params = $this->appendLocationMetadata($picked, $params);

        $qualityParams = $this->qualityAggregator->buildParams($picked);
        foreach ($qualityParams as $qualityKey => $qualityValue) {
            if ($qualityValue !== null) {
                $params[$qualityKey] = $qualityValue;
            }
        }

        $peopleParams = $this->buildPeopleParams($picked);
        $params       = [...$params, ...$peopleParams];

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($picked)
            ),
        ];
    }
}
