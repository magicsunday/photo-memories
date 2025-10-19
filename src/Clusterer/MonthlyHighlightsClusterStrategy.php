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
use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ContextualClusterBridgeTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterDeviceMetadataAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\GeoTemporalClusterTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function abs;
use function array_map;
use function array_sum;
use function array_values;
use function assert;
use function count;
use function krsort;
use function max;
use function min;
use function round;
use function substr;
use function usort;

/**
 * Builds a highlight memory for each (year, month) with sufficient coverage.
 */
final readonly class MonthlyHighlightsClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use ContextualClusterBridgeTrait;
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use GeoTemporalClusterTrait;
    use ProgressAwareClusterTrait;

    private ClusterQualityAggregator $qualityAggregator;

    private ClusterDeviceMetadataAggregator $deviceAggregator;

    private GeoDbscanHelper $dbscanHelper;

    public function __construct(
        private LocationHelper $locationHelper,
        private string $timezone = 'Europe/Berlin',
        private int $minItemsPerMonth = 40,
        private int $minDistinctDays = 10,
        private int $maxEventsPerMonth = 5,
        private int $minItemsPerEvent = 6,
        private int $eventWindowSeconds = 21_600,
        private float $eventRadiusMeters = 350.0,
        private int $minSpacingSeconds = 36 * 3600,
        ?ClusterQualityAggregator $qualityAggregator = null,
        ?ClusterDeviceMetadataAggregator $deviceAggregator = null,
        ?GeoDbscanHelper $dbscanHelper = null,
    ) {
        if ($this->minItemsPerMonth < 1) {
            throw new InvalidArgumentException('minItemsPerMonth must be >= 1.');
        }

        if ($this->minDistinctDays < 1) {
            throw new InvalidArgumentException('minDistinctDays must be >= 1.');
        }

        if ($this->maxEventsPerMonth < 1) {
            throw new InvalidArgumentException('maxEventsPerMonth must be >= 1.');
        }

        if ($this->minItemsPerEvent < 1) {
            throw new InvalidArgumentException('minItemsPerEvent must be >= 1.');
        }

        if ($this->eventWindowSeconds < 1) {
            throw new InvalidArgumentException('eventWindowSeconds must be >= 1.');
        }

        if ($this->eventRadiusMeters <= 0.0) {
            throw new InvalidArgumentException('eventRadiusMeters must be > 0.');
        }

        if ($this->minSpacingSeconds < 0) {
            throw new InvalidArgumentException('minSpacingSeconds must be >= 0.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
        $this->deviceAggregator  = $deviceAggregator ?? new ClusterDeviceMetadataAggregator();
        $this->dbscanHelper      = $dbscanHelper ?? new GeoDbscanHelper();
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
            $year  = (int) substr($ym, 0, 4);
            $month = (int) substr($ym, 5, 2);
            $label = $this->germanMonthLabel($month) . ' ' . $year;
            $monthBounds = $this->computeMonthBounds($ym, $tz);

            $events = $this->deriveMonthlyEvents($list, $tz);
            if ($events === []) {
                continue;
            }

            $candidates = $this->scoreMonthlyEvents($events, $monthBounds);
            if ($candidates === []) {
                continue;
            }

            $selected = $this->selectEventCandidates($candidates);
            if ($selected === []) {
                continue;
            }

            foreach ($selected as $candidate) {
                /** @var list<Media> $members */
                $members = $candidate['members'];

                $params = [
                    'year'          => $year,
                    'month'         => $month,
                    'label'         => $label,
                    'time_range'    => $candidate['time_range'],
                    'members_count' => count($members),
                    'score_total'   => $candidate['score_total'],
                    'score_mix'     => $this->normaliseScoreMix($candidate['score_mix']),
                ];

                $tagMetadata = $this->collectDominantTags($members);
                if ($tagMetadata !== []) {
                    $params = [...$params, ...$tagMetadata];
                }

                $params = $this->appendLocationMetadata($members, $params);

                foreach ($candidate['quality'] as $qualityKey => $qualityValue) {
                    if ($qualityValue !== null) {
                        $params[$qualityKey] = $qualityValue;
                    }
                }

                $params = [...$params, ...$candidate['people']];

                $deviceSummary = $this->deviceAggregator->summarize($members);
                foreach ($deviceSummary as $deviceKey => $deviceValue) {
                    if ($deviceValue !== null) {
                        $params[$deviceKey] = $deviceValue;
                    }
                }

                $out[] = new ClusterDraft(
                    algorithm: $this->name(),
                    params: $params,
                    centroid: [
                        'lat' => (float) $candidate['centroid']['lat'],
                        'lon' => (float) $candidate['centroid']['lon'],
                    ],
                    members: $this->toMemberIds($members)
                );
            }
        }

        return $out;
    }

    /**
     * @param list<Media> $items
     *
     * @return list<list<Media>>
     */
    private function deriveMonthlyEvents(array $items, DateTimeZone $tz): array
    {
        $buckets = $this->buildGeoTemporalBuckets(
            $items,
            $this->dbscanHelper,
            $this->minItemsPerEvent,
            $this->eventRadiusMeters,
            $this->eventWindowSeconds,
        );

        if ($buckets === []) {
            $buckets = $this->buildFallbackEvents($items, $tz);
        }

        if ($buckets === []) {
            return [];
        }

        return array_map(
            static function (array $members): array {
                usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                return $members;
            },
            $buckets,
        );
    }

    /**
     * @param list<Media> $items
     *
     * @return list<list<Media>>
     */
    private function buildFallbackEvents(array $items, DateTimeZone $tz): array
    {
        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $dayKey = $takenAt->setTimezone($tz)->format('Y-m-d');
            $byDay[$dayKey] ??= [];
            $byDay[$dayKey][] = $media;
        }

        /** @var list<list<Media>> $events */
        $events = [];

        foreach ($byDay as $members) {
            if (count($members) < $this->minItemsPerEvent) {
                continue;
            }

            usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $events[] = $members;
        }

        if ($events === [] && count($items) >= $this->minItemsPerEvent) {
            $copy = $items;
            usort($copy, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $events[] = $copy;
        }

        return $events;
    }

    /**
     * @param list<list<Media>> $events
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $monthBounds
     *
     * @return list<array{
     *     members: list<Media>,
     *     centroid: array{lat: float, lon: float},
     *     time_range: array{from:int,to:int},
     *     quality: array<string, mixed>,
     *     people: array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float},
     *     score_mix: array<string, float>,
     *     score_total: float
     * }>
     */
    private function scoreMonthlyEvents(array $events, array $monthBounds): array
    {
        $scored = [];

        foreach ($events as $members) {
            if (count($members) < $this->minItemsPerEvent) {
                continue;
            }

            $timeRange = $this->computeTimeRange($members);
            $centroid  = $this->computeCentroid($members);

            $quality = $this->qualityAggregator->buildParams($members);
            $people  = $this->buildPeopleParams($members);

            $scoreMix = $this->buildScoreMix($members, $timeRange, $monthBounds['start'], $monthBounds['end'], $quality, $people);
            $score    = array_sum($scoreMix);

            $scored[] = [
                'members'    => $members,
                'centroid'   => $centroid,
                'time_range' => $timeRange,
                'quality'    => $quality,
                'people'     => $people,
                'score_mix'  => $scoreMix,
                'score_total'=> $score,
            ];
        }

        return $scored;
    }

    /**
     * @param list<array{
     *     members: list<Media>,
     *     centroid: array{lat: float, lon: float},
     *     time_range: array{from:int,to:int},
     *     quality: array<string, mixed>,
     *     people: array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float},
     *     score_mix: array<string, float>,
     *     score_total: float
     * }> $candidates
     *
     * @return list<array{
     *     members: list<Media>,
     *     centroid: array{lat: float, lon: float},
     *     time_range: array{from:int,to:int},
     *     quality: array<string, mixed>,
     *     people: array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float},
     *     score_mix: array<string, float>,
     *     score_total: float
     * }>
     */
    private function selectEventCandidates(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        usort(
            $candidates,
            static function (array $left, array $right): int {
                $scoreCmp = $right['score_total'] <=> $left['score_total'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                return $left['time_range']['from'] <=> $right['time_range']['from'];
            }
        );

        $topCandidate = $candidates[0];

        /** @var list<array<string, mixed>> $selected */
        $selected = [];

        foreach ($candidates as $candidate) {
            $start = $candidate['time_range']['from'];

            $eligible = true;
            foreach ($selected as $existing) {
                $distance = abs($start - $existing['time_range']['from']);
                if ($distance < $this->minSpacingSeconds) {
                    $eligible = false;
                    break;
                }
            }

            if (!$eligible) {
                continue;
            }

            $selected[] = $candidate;

            if (count($selected) >= $this->maxEventsPerMonth) {
                break;
            }
        }

        if ($selected === []) {
            $selected[] = $topCandidate;
        }

        usort(
            $selected,
            static fn (array $left, array $right): int => $right['time_range']['from'] <=> $left['time_range']['from']
        );

        return array_values($selected);
    }

    /**
     * @param list<Media> $members
     * @param array{from:int,to:int} $timeRange
     * @param array<string, mixed> $quality
     * @param array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float} $people
     *
     * @return array<string, float>
     */
    private function buildScoreMix(
        array $members,
        array $timeRange,
        DateTimeImmutable $monthStart,
        DateTimeImmutable $monthEnd,
        array $quality,
        array $people,
    ): array {
        $membersCount = count($members);
        $quantityShare = min(1.0, $membersCount / max(1, (int) ($this->minItemsPerEvent * 1.5)));

        $qualityAvg = (float) ($quality['quality_avg'] ?? 0.0);
        $qualityShare = max(0.0, min(1.0, $qualityAvg));

        $peopleScore = (float) ($people['people'] ?? 0.0);
        $peopleShare = max(0.0, min(1.0, $peopleScore));

        $startTs = $monthStart->getTimestamp();
        $endTs   = $monthEnd->getTimestamp();
        $midpoint = ($timeRange['from'] + $timeRange['to']) / 2;
        $recencyShare = $this->normaliseRange($midpoint, $startTs, $endTs);

        return [
            'quantity' => $quantityShare * 30.0,
            'quality'  => $qualityShare * 35.0,
            'people'   => $peopleShare * 20.0,
            'recency'  => $recencyShare * 15.0,
        ];
    }

    private function normaliseRange(float $value, float $minValue, float $maxValue): float
    {
        if ($maxValue <= $minValue) {
            return 0.0;
        }

        if ($value <= $minValue) {
            return 0.0;
        }

        if ($value >= $maxValue) {
            return 1.0;
        }

        return ($value - $minValue) / ($maxValue - $minValue);
    }

    /**
     * @param array<string, float> $mix
     *
     * @return array<string, float>
     */
    private function normaliseScoreMix(array $mix): array
    {
        $normalised = [];

        foreach ($mix as $key => $value) {
            $normalised[$key] = round($value, 4);
        }

        return $normalised;
    }

    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    private function computeMonthBounds(string $ym, DateTimeZone $tz): array
    {
        $start = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
        $end   = $start->modify('last day of this month')->setTime(23, 59, 59);

        return ['start' => $start, 'end' => $end];
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
