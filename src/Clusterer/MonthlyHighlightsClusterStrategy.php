<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterDeviceMetadataAggregator;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;

use function assert;
use function count;
use function krsort;
use function substr;
use function usort;

/**
 * Builds a highlight memory for each (year, month) with sufficient coverage.
 */
final readonly class MonthlyHighlightsClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ProgressAwareClusterTrait;

    private ClusterQualityAggregator $qualityAggregator;

    private ClusterDeviceMetadataAggregator $deviceAggregator;

    public function __construct(
        private string $timezone = 'Europe/Berlin',
        private int $minItemsPerMonth = 40,
        private int $minDistinctDays = 10,
        ?ClusterQualityAggregator $qualityAggregator = null,
        ?ClusterDeviceMetadataAggregator $deviceAggregator = null,
    ) {
        if ($this->minItemsPerMonth < 1) {
            throw new InvalidArgumentException('minItemsPerMonth must be >= 1.');
        }

        if ($this->minDistinctDays < 1) {
            throw new InvalidArgumentException('minDistinctDays must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
        $this->deviceAggregator  = $deviceAggregator ?? new ClusterDeviceMetadataAggregator();
    }

    public function name(): string
    {
        return 'monthly_highlights';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     *
     * @throws DateInvalidTimeZoneException
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $byYm */
        $byYm = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $local = $t->setTimezone($tz);
            $ym    = $local->format('Y-m');
            $byYm[$ym] ??= [];
            $byYm[$ym][] = $m;
        }

        $eligibleMonths = $this->filterGroupsByMinItems($byYm, $this->minItemsPerMonth);

        $eligibleMonths = $this->filterGroups(
            $eligibleMonths,
            function (array $list) use ($tz): bool {
                /** @var array<string,bool> $days */
                $days = [];
                foreach ($list as $m) {
                    $takenAt = $m->getTakenAt();
                    assert($takenAt instanceof DateTimeImmutable);
                    $days[$takenAt->setTimezone($tz)->format('Y-m-d')] = true;
                }

                $count = count($days);

                return $count >= $this->minDistinctDays;
            }
        );

        if ($eligibleMonths === []) {
            return [];
        }

        krsort($eligibleMonths, SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleMonths as $ym => $list) {
            usort($list, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = $this->computeCentroid($list);
            $time     = $this->computeTimeRange($list);

            $year  = (int) substr($ym, 0, 4);
            $month = (int) substr($ym, 5, 2);
            $label = $this->germanMonthLabel($month) . ' ' . $year;

            $params = [
                'year'       => $year,
                'month'      => $month,
                'time_range' => $time,
            ];

            $tags = $this->collectDominantTags($list);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $qualityParams = $this->qualityAggregator->buildParams($list);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $peopleParams = $this->buildPeopleParams($list);
            $params       = [...$params, ...$peopleParams];

            $deviceSummary = $this->deviceAggregator->summarize($list);
            foreach ($deviceSummary as $deviceKey => $deviceValue) {
                if ($deviceValue !== null) {
                    $params[$deviceKey] = $deviceValue;
                }
            }

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($list)
            );
        }

        return $out;
    }

    private function germanMonthLabel(int $m): string
    {
        return match ($m) {
            1       => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5       => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9       => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
            default => 'Monat',
        };
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, callable $update): array
    {
        return $this->runWithDefaultProgress($items, $update, fn (array $payload): array => $this->cluster($payload));
    }

}
