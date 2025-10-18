<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Support\PersonSignatureHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\SelectionStageInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\Support\FaceMetricHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function floor;
use function hexdec;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function str_contains;
use function strtolower;
use function substr;
use function strlen;
use function trim;
use function usort;

/**
 * Policy driven implementation coordinating individual selection stages.
 */
final class PolicyDrivenMemberSelector implements ClusterMemberSelectorInterface
{
    private const STAYPOINT_MERGE_METERS = 120.0;

    /**
     * @var list<string>
     */
    private const MOTIF_BUCKETS = [
        'person_group',
        'landmark',
        'food',
        'indoor',
        'outdoor',
        'night',
        'panorama',
    ];

    /**
     * @var array<string, list<int>>
     */
    private array $phashCache = [];

    /**
     * @var list<SelectionStageInterface>
     */
    private readonly array $hardStages;

    /**
     * @var list<SelectionStageInterface>
     */
    private readonly array $softStages;

    private readonly PersonSignatureHelper $personHelper;

    /**
     * @param iterable<SelectionStageInterface> $hardStages
     * @param iterable<SelectionStageInterface> $softStages
     */
    public function __construct(
        iterable $hardStages,
        iterable $softStages,
    ) {
        $this->hardStages   = array_values(is_iterable($hardStages) ? [...$hardStages] : []);
        $this->softStages   = array_values(is_iterable($softStages) ? [...$softStages] : []);
        $this->personHelper = new PersonSignatureHelper();

        if ($this->hardStages === []) {
            throw new InvalidArgumentException('At least one hard selection stage must be configured.');
        }
    }

    public function select(string $algorithm, array $memberIds, ?MemberSelectionContext $context = null): MemberSelectionResult
    {
        if ($context === null) {
            throw new InvalidArgumentException('MemberSelectionContext is required for policy driven selection.');
        }

        if ($memberIds === []) {
            return new MemberSelectionResult([], [
                'storyline' => $context->getDraft()->getStoryline(),
                'counts' => [
                    'considered' => 0,
                    'eligible'   => 0,
                    'selected'   => 0,
                ],
                'rejections' => [],
                'policy'     => $this->policySnapshot($context->getPolicy(), $context->getDraft()->getStoryline()),
                'stages'     => [
                    'hard' => array_map(static fn (SelectionStageInterface $stage): string => $stage->getName(), $this->hardStages),
                    'soft' => array_map(static fn (SelectionStageInterface $stage): string => $stage->getName(), $this->softStages),
                ],
            ]);
        }

        $policy   = $context->getPolicy();
        $draft    = $context->getDraft();
        $mediaMap = $context->getMediaMap();

        $candidates = $this->buildCandidates(
            $memberIds,
            $mediaMap,
            $context->getQualityScores(),
            $policy,
            $draft,
            $context->getDaySegments(),
        );

        $telemetry = [
            'storyline' => $draft->getStoryline(),
            'counts' => [
                'considered' => count($candidates['all']),
                'eligible'   => count($candidates['eligible']),
                'selected'   => 0,
            ],
            'rejections' => [
                'no_show'             => $candidates['drops']['no_show'],
                'quality'             => $candidates['drops']['quality'],
                'burst'               => $candidates['drops']['burst'] ?? 0,
                SelectionTelemetry::REASON_TIME_GAP       => 0,
                SelectionTelemetry::REASON_DAY_QUOTA     => 0,
                SelectionTelemetry::REASON_TIME_SLOT     => 0,
                SelectionTelemetry::REASON_STAYPOINT      => 0,
                SelectionTelemetry::REASON_PHASH          => 0,
                SelectionTelemetry::REASON_SCENE          => 0,
                SelectionTelemetry::REASON_ORIENTATION    => 0,
                SelectionTelemetry::REASON_PEOPLE         => 0,
            ],
            'policy' => $this->policySnapshot($policy, $draft->getStoryline()),
            'stages' => [
                'hard' => array_map(static fn (SelectionStageInterface $stage): string => $stage->getName(), $this->hardStages),
                'soft' => array_map(static fn (SelectionStageInterface $stage): string => $stage->getName(), $this->softStages),
            ],
        ];

        $eligibleCandidates = $candidates['eligible'];
        if ($eligibleCandidates === []) {
            return new MemberSelectionResult([], $telemetry);
        }

        $attempts = [
            static fn (SelectionPolicy $p): SelectionPolicy => $p,
            static fn (SelectionPolicy $p): SelectionPolicy => $p->withRelaxedSpacing(
                max(25, (int) floor($p->getMinSpacingSeconds() * 0.6))
            ),
            static fn (SelectionPolicy $p): SelectionPolicy => $p->withRelaxedHamming(
                max(8, (int) floor($p->getPhashMinHamming() * 0.75))
            ),
        ];

        if (
            $policy->getRelaxedMaxPerStaypoint() !== null
            && $policy->getMaxPerStaypoint() !== null
            && $policy->getRelaxedMaxPerStaypoint() > $policy->getMaxPerStaypoint()
        ) {
            $attempts[] = static function (SelectionPolicy $p): SelectionPolicy {
                $relaxed = $p->getRelaxedMaxPerStaypoint();
                if ($relaxed === null) {
                    return $p;
                }

                return $p->withMaxPerStaypoint($relaxed);
            };
        }

        $attempts[] = static fn (SelectionPolicy $p): SelectionPolicy => $p->withoutCaps();

        $selected        = [];
        $appliedPolicy   = $policy;
        $relaxations     = [];
        $finalCollector  = new SelectionTelemetry();
        $candidatePolicy = $policy;

        foreach ($attempts as $index => $mutator) {
            $candidatePolicy = $mutator($candidatePolicy);
            $collector       = new SelectionTelemetry();
            $attemptResult   = $this->runPipeline($eligibleCandidates, $candidatePolicy, $collector);

            $shouldAccept = count($attemptResult) >= $policy->getMinimumTotal() || $index === (count($attempts) - 1);
            if ($shouldAccept) {
                $selected       = $attemptResult;
                $appliedPolicy  = $candidatePolicy;
                $finalCollector = $collector;

                break;
            }

            $relaxations[] = [
                'step'    => $index,
                'members' => count($attemptResult),
                'policy'  => $this->policySnapshot($candidatePolicy, $draft->getStoryline()),
            ];
        }

        if ($relaxations !== []) {
            $telemetry['relaxations'] = $relaxations;
            $telemetry['policy']      = $this->policySnapshot($appliedPolicy, $draft->getStoryline());
        }

        foreach ($finalCollector->reasonCounts() as $reason => $count) {
            $telemetry['rejections'][$reason] = $count;
        }

        $minimumRequired = max(0, $policy->getMinimumTotal());
        $paddingAdded    = 0;

        if ($minimumRequired > 0 && count($selected) < $minimumRequired) {
            $selectedIds = [];
            foreach ($selected as $candidate) {
                $selectedIds[$candidate['id']] = true;
            }

            foreach ($eligibleCandidates as $candidate) {
                if (isset($selectedIds[$candidate['id']])) {
                    continue;
                }

                $selected[]                     = $candidate;
                $selectedIds[$candidate['id']]  = true;
                ++$paddingAdded;

                if (count($selected) >= $minimumRequired) {
                    break;
                }
            }

            if ($paddingAdded > 0) {
                usort($selected, static function (array $a, array $b): int {
                    if ($a['timestamp'] === $b['timestamp']) {
                        return $a['id'] <=> $b['id'];
                    }

                    return $a['timestamp'] <=> $b['timestamp'];
                });

                $telemetry['padding'] = [
                    'added'         => $paddingAdded,
                    'eligible_pool' => count($eligibleCandidates),
                ];
            }
        }

        $telemetry['counts']['selected'] = count($selected);
        if ($paddingAdded > 0) {
            $telemetry['counts']['padded'] = $paddingAdded;
        }

        $this->enrichSelectionTelemetry($telemetry, $selected);

        $memberIds = array_map(static fn (array $candidate): int => $candidate['id'], $selected);

        return new MemberSelectionResult($memberIds, $telemetry);
    }

    /**
     * @param array<string, mixed>            $telemetry
     * @param list<array<string, mixed>>      $selected
     */
    private function enrichSelectionTelemetry(array &$telemetry, array $selected): void
    {
        $timeGaps       = $this->collectTimeGaps($selected);
        $phashDistances = $this->collectPhashDistances($selected);
        $faceMetrics    = $this->collectFaceMetrics($selected);

        $telemetry['metrics'] = [
            'time_gaps'       => $timeGaps,
            'phash_distances' => $phashDistances,
            'faces'           => $faceMetrics,
        ];

        $telemetry['distribution'] = [
            'per_day'    => $this->countByKey($selected, 'day'),
            'per_year'   => $this->countByKey($selected, 'year'),
            'per_bucket' => $this->countByKey($selected, 'bucket'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $selected
     *
     * @return list<int>
     */
    private function collectTimeGaps(array $selected): array
    {
        $samples   = [];
        $previous  = null;

        foreach ($selected as $candidate) {
            $timestamp = $candidate['timestamp'] ?? null;
            if (!is_int($timestamp)) {
                continue;
            }

            if ($previous !== null) {
                $samples[] = abs($timestamp - $previous);
            }

            $previous = $timestamp;
        }

        return $samples;
    }

    /**
     * @param list<array<string, mixed>> $selected
     *
     * @return list<int>
     */
    private function collectPhashDistances(array $selected): array
    {
        $samples  = [];
        $previous = null;

        foreach ($selected as $candidate) {
            $bits = $candidate['hash_bits'] ?? null;
            if (!is_array($bits)) {
                $previous = null;

                continue;
            }

            if ($previous !== null) {
                $distance = $this->calculateHammingDistance($previous, $bits);
                if ($distance !== null) {
                    $samples[] = $distance;
                }
            }

            $previous = $bits;
        }

        return $samples;
    }

    /**
     * @param list<array<string, mixed>> $selected
     *
     * @return array<string, mixed>
     */
    private function collectFaceMetrics(array $selected): array
    {
        $withFaces       = 0;
        $sumFaces        = 0;
        $groupShots      = 0;
        $closeUps        = 0;
        $coverageSum     = 0.0;
        $coverageSamples = 0;

        foreach ($selected as $candidate) {
            $metrics = $candidate['face_metrics'] ?? null;
            $hasFaces = ($candidate['has_faces'] ?? false) === true;

            if (!is_array($metrics)) {
                if ($hasFaces) {
                    ++$withFaces;
                }

                continue;
            }

            $count    = (int) ($metrics['count'] ?? 0);
            $coverage = $metrics['largest_coverage'] ?? null;

            if ($count > 0) {
                ++$withFaces;
                $sumFaces += $count;
            } elseif ($hasFaces) {
                ++$withFaces;
            }

            if (is_numeric($coverage)) {
                $normalised = FaceMetricHelper::normaliseCoverage((float) $coverage);
                if ($normalised !== null) {
                    $coverageSum += $normalised;
                    ++$coverageSamples;
                    $coverage = $normalised;
                }
            } else {
                $coverage = null;
            }

            if (FaceMetricHelper::isGroupShot($count, $coverage)) {
                ++$groupShots;
            }

            if (FaceMetricHelper::isDominantCloseUp($coverage)) {
                ++$closeUps;
            }
        }

        return [
            'with_faces'          => $withFaces,
            'group_shots'         => $groupShots,
            'closeups'            => $closeUps,
            'average_count'       => $withFaces > 0 ? $sumFaces / $withFaces : 0.0,
            'average_max_coverage' => $coverageSamples > 0 ? $coverageSum / $coverageSamples : null,
        ];
    }

    /**
     * @param array<int, int> $a
     * @param array<int, int> $b
     */
    private function calculateHammingDistance(array $a, array $b): ?int
    {
        if ($a === [] || $b === []) {
            return null;
        }

        $length   = min(count($a), count($b));
        $distance = abs(count($a) - count($b));

        for ($idx = 0; $idx < $length; ++$idx) {
            if (($a[$idx] ?? null) !== ($b[$idx] ?? null)) {
                ++$distance;
            }
        }

        return $distance;
    }

    /**
     * @return array{count: int, largest_coverage: ?float}
     */
    private function resolveFaceMetrics(Media $media): array
    {
        $count    = max(0, $media->getFacesCount());
        $coverage = $this->resolveLargestFaceCoverage($media);

        return [
            'count'            => $count,
            'largest_coverage' => $coverage,
        ];
    }

    private function resolveLargestFaceCoverage(Media $media): ?float
    {
        $features = $media->getFeatures();
        if (!is_array($features) || $features === []) {
            return null;
        }

        $candidates = [
            ['faces', 'largest_coverage'],
            ['faces', 'largestCoverage'],
            ['faces', 'max_coverage'],
            ['faces', 'maxCoverage'],
            ['faces', 'dominant_coverage'],
            ['vision', 'largest_face_coverage'],
            ['vision', 'largestFaceCoverage'],
            ['vision', 'face_coverage_largest'],
            ['people', 'face_coverage_largest'],
            ['people', 'largest_face_coverage'],
        ];

        foreach ($candidates as [$namespace, $key]) {
            $value = $features[$namespace][$key] ?? null;
            if (is_numeric($value)) {
                return FaceMetricHelper::normaliseCoverage((float) $value);
            }
        }

        foreach (['faces', 'vision', 'people'] as $namespace) {
            $payload = $features[$namespace] ?? null;
            if (!is_array($payload)) {
                continue;
            }

            foreach ($payload as $value) {
                if (is_numeric($value)) {
                    $normalised = FaceMetricHelper::normaliseCoverage((float) $value);
                    if ($normalised !== null) {
                        return $normalised;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $selected
     *
     * @return array<int|string, int>
     */
    private function countByKey(array $selected, string $key): array
    {
        $counts = [];

        foreach ($selected as $candidate) {
            if (!array_key_exists($key, $candidate)) {
                continue;
            }

            $value = $candidate[$key];
            if ($value === null || $value === '') {
                continue;
            }

            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param list<int>              $memberIds
     * @param array<int, Media>      $mediaMap
     * @param array<int, float|null> $qualityScores
     *
     * @return array{eligible: list<array<string, mixed>>, drops: array<string, int>, all: list<int>}
     */
    private function buildCandidates(
        array $memberIds,
        array $mediaMap,
        array $qualityScores,
        SelectionPolicy $policy,
        ClusterDraft $draft,
        array $daySegments,
    ): array {
        $eligible = [];
        $drops    = [
            'no_show' => 0,
            'quality' => 0,
        ];

        $staypointCenters = [];
        $all              = [];
        $personFrequency  = [];

        foreach ($memberIds as $id) {
            $media = $mediaMap[$id] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            $all[] = $id;

            if ($media->isNoShow() || $media->isLowQuality()) {
                ++$drops['no_show'];

                continue;
            }

            $timestamp = $this->resolveTimestamp($media);
            $quality   = $qualityScores[$id] ?? $media->getQualityScore() ?? 0.0;
            if ($quality < $policy->getQualityFloor()) {
                ++$drops['quality'];

                continue;
            }

            $day       = $this->formatDay($media, $timestamp);
            $slot      = $this->resolveSlot($media, $timestamp, $policy);
            $stayId    = $this->assignStaypoint($media, $staypointCenters);
            $persons   = $this->personHelper->personIds($media);
            $faceMetrics = $this->resolveFaceMetrics($media);
            $facesCount = $faceMetrics['count'];
            $hasFaces  = $media->hasFaces() || $facesCount > 0;
            $isVideo   = $media->isVideo();
            $bucket    = $this->deriveSceneBucket($draft, $media, $faceMetrics);
            $year      = (int) (new DateTimeImmutable('@' . $timestamp))->format('Y');
            $orientation= $this->resolveOrientationType($media);

            $score = $quality;
            if ($isVideo) {
                $score += $policy->getVideoBonus();
            }

            if ($hasFaces) {
                $score += $policy->getFaceBonus();
            }

            $groupBonus = 0.0;
            if ($facesCount >= FaceMetricHelper::GROUP_FACE_COUNT_THRESHOLD) {
                $groupBonus = $policy->getFaceBonus() * FaceMetricHelper::groupBonusScale($facesCount);
                $score += $groupBonus;
            }

            $coverage        = $faceMetrics['largest_coverage'];
            $closeUpPenalty  = 0.0;
            if ($policy->getSelfiePenalty() > 0.0) {
                $penaltyFactor = FaceMetricHelper::closeUpPenaltyFactor($coverage);
                if ($penaltyFactor > 0.0) {
                    $closeUpPenalty = $policy->getSelfiePenalty() * $penaltyFactor;
                    $score -= $closeUpPenalty;
                }
            }

            if ($this->isLikelySelfie($media) && $policy->getSelfiePenalty() > 0.0) {
                $score -= $policy->getSelfiePenalty();
            }

            $hashBits = $this->decodeHash($media);

            $cohortPenalty = 0.0;
            if ($policy->getCohortPenalty() > 0.0 && $persons !== []) {
                $repeatCount = 0;
                foreach ($persons as $personId) {
                    $repeatCount += $personFrequency[$personId] ?? 0;
                }

                if ($repeatCount > 0) {
                    $cohortPenalty = $policy->getCohortPenalty() * $repeatCount;
                    $score -= $cohortPenalty;
                }
            }

            $dayInfo    = $daySegments[$day] ?? ['category' => 'peripheral', 'score' => 0.0, 'duration' => null];
            $dayCategory = is_string($dayInfo['category'] ?? null) && $dayInfo['category'] !== ''
                ? $dayInfo['category']
                : 'peripheral';
            $dayScore = $dayInfo['score'] ?? 0.0;
            if (!is_float($dayScore) && !is_int($dayScore)) {
                $dayScore = is_numeric($dayScore) ? (float) $dayScore : 0.0;
            }

            $dayDuration = $dayInfo['duration'] ?? null;
            if (!is_int($dayDuration)) {
                if (is_numeric($dayDuration)) {
                    $candidateDuration = (int) $dayDuration;
                    $dayDuration       = $candidateDuration >= 0 ? $candidateDuration : null;
                } else {
                    $dayDuration = null;
                }
            }

            $eligible[] = [
                'id'         => $id,
                'media'      => $media,
                'timestamp'  => $timestamp,
                'day'        => $day,
                'slot'       => $slot,
                'staypoint'  => $stayId,
                'score'      => max(0.0, $score),
                'is_video'   => $isVideo,
                'persons'    => $media->getPersons() ?? [],
                'person_ids' => $persons,
                'has_faces'  => $hasFaces,
                'hash_bits'  => $hashBits,
                'year'       => $year,
                'bucket'     => $bucket,
                'orientation'=> $orientation,
                'burst'      => $media->getBurstUuid(),
                'day_category' => $dayCategory,
                'day_score'    => (float) $dayScore,
                'day_duration' => $dayDuration,
                'cohort_penalty' => $cohortPenalty,
                'face_metrics' => [
                    'count'            => $facesCount,
                    'largest_coverage' => $coverage,
                    'group_bonus'      => $groupBonus,
                    'closeup_penalty'  => $closeUpPenalty,
                ],
            ];

            if ($persons !== []) {
                foreach ($persons as $personId) {
                    $personFrequency[$personId] = ($personFrequency[$personId] ?? 0) + 1;
                }
            }
        }

        $eligible = $this->collapseBursts($eligible, $drops);

        return [
            'eligible' => $eligible,
            'drops'    => $drops,
            'all'      => $all,
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function runPipeline(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $current = $candidates;
        foreach ($this->hardStages as $stage) {
            $current = $stage->apply($current, $policy, $telemetry);
            if ($current === []) {
                return [];
            }
        }

        foreach ($this->softStages as $stage) {
            $current = $stage->apply($current, $policy, $telemetry);
            if ($current === []) {
                return [];
            }
        }

        $limit = min(
            $policy->getTargetTotal(),
            max($policy->getMinimumTotal(), count($current))
        );

        if ($limit <= 0) {
            return [];
        }

        $current = array_slice($current, 0, $limit);

        if ($current === []) {
            return [];
        }

        usort($current, static function (array $a, array $b): int {
            if ($a['timestamp'] === $b['timestamp']) {
                return $a['id'] <=> $b['id'];
            }

            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $current;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function collapseBursts(array $candidates, array &$drops): array
    {
        $byBurst = [];
        $singles = [];

        foreach ($candidates as $candidate) {
            $burst = $candidate['burst'];
            if ($burst === null || $burst === '') {
                $singles[] = $candidate;

                continue;
            }

            $byBurst[$burst] ??= [];
            $byBurst[$burst][] = $candidate;
        }

        foreach ($byBurst as $list) {
            usort($list, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $singles[] = $list[0];
            $drops['burst'] = ($drops['burst'] ?? 0) + (count($list) - 1);
        }

        usort($singles, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return $a['timestamp'] <=> $b['timestamp'];
            }

            return $a['score'] < $b['score'] ? 1 : -1;
        });

        return $singles;
    }

    private function resolveTimestamp(Media $media): int
    {
        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            return $takenAt->getTimestamp();
        }

        return $media->getCreatedAt()->getTimestamp();
    }

    private function formatDay(Media $media, int $timestamp): string
    {
        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            return $takenAt->format('Y-m-d');
        }

        return (new DateTimeImmutable('@' . $timestamp))->format('Y-m-d');
    }

    private function resolveSlot(Media $media, int $timestamp, SelectionPolicy $policy): ?int
    {
        $hours = $policy->getTimeSlotHours();
        if ($hours === null || $hours <= 0.0) {
            return null;
        }

        $takenAt = $media->getTakenAt();
        $hour    = (int) (($takenAt instanceof DateTimeImmutable ? (int) $takenAt->format('H') : (int) (new DateTimeImmutable('@' . $timestamp))->format('H')));

        return (int) floor($hour / $hours);
    }

    /**
     * @param array<int, array{lat: float, lon: float}> $staypointCenters
     */
    private function assignStaypoint(Media $media, array &$staypointCenters): ?int
    {
        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();
        if ($lat === null || $lon === null) {
            return null;
        }

        foreach ($staypointCenters as $id => $center) {
            $distance = MediaMath::haversineDistanceInMeters($lat, $lon, $center['lat'], $center['lon']);
            if ($distance <= self::STAYPOINT_MERGE_METERS) {
                return $id;
            }
        }

        $id = count($staypointCenters) + 1;
        $staypointCenters[$id] = ['lat' => $lat, 'lon' => $lon];

        return $id;
    }

    private function decodeHash(Media $media): ?array
    {
        $hash = $media->getPhash64();
        if ($hash === null || $hash === '') {
            $hash = $media->getPhash();
        }

        if ($hash === null || $hash === '') {
            return null;
        }

        $hash = strtolower($hash);
        $cacheKey = $media->getId() . ':' . $hash;
        if (array_key_exists($cacheKey, $this->phashCache)) {
            return $this->phashCache[$cacheKey];
        }

        $bits = [];
        $length = min(16, strlen($hash));
        for ($i = 0; $i < $length; ++$i) {
            $nibble = hexdec($hash[$i]);
            for ($b = 3; $b >= 0; --$b) {
                $bits[] = ($nibble >> $b) & 1;
            }
        }

        $this->phashCache[$cacheKey] = $bits;

        return $bits;
    }

    private function isLikelySelfie(Media $media): bool
    {
        $persons = $media->getPersons();
        if ($persons === null) {
            return false;
        }

        return count($persons) === 1 && $media->hasFaces();
    }

    private function deriveSceneBucket(ClusterDraft $draft, Media $media, array $faceMetrics): string
    {
        $params    = $draft->getParams();
        $faceCount = (int) ($faceMetrics['count'] ?? 0);
        $coverage  = $faceMetrics['largest_coverage'] ?? null;
        if (is_numeric($coverage)) {
            $coverage = (float) $coverage;
        } else {
            $coverage = null;
        }

        if ($this->isPanoramaScene($media)) {
            return 'panorama';
        }

        if ($this->isGroupScene($faceCount, $coverage)) {
            return 'person_group';
        }

        $poiBucket     = $this->bucketFromPoi($params);
        $foodScene     = $this->looksLikeFood($media);
        $nightScene    = $this->isNightScene($media);
        $landmarkScene = $this->looksLikeLandmark($media, $params);

        if ($poiBucket === 'food') {
            return 'food';
        }

        if ($foodScene) {
            return 'food';
        }

        if ($poiBucket === 'landmark' && !$nightScene) {
            return 'landmark';
        }

        if ($nightScene) {
            return 'night';
        }

        if ($landmarkScene) {
            return 'landmark';
        }

        if ($this->isIndoorScene($media, $poiBucket)) {
            return 'indoor';
        }

        return 'outdoor';
    }

    private function bucketFromPoi(array $params): ?string
    {
        $categoryKey   = $this->stringOrNull($params['poi_category_key'] ?? null);
        $categoryValue = $this->stringOrNull($params['poi_category_value'] ?? null);
        $label         = $this->stringOrNull($params['poi_label'] ?? null);
        $tags          = $this->stringMap($params['poi_tags'] ?? null);

        if ($this->poiMatchesFood($categoryKey, $categoryValue, $tags, $label)) {
            return 'food';
        }

        if ($this->poiMatchesLandmark($categoryKey, $categoryValue, $tags, $label)) {
            return 'landmark';
        }

        return null;
    }

    private function isPanoramaScene(Media $media): bool
    {
        $panorama = $media->isPanorama();
        if ($panorama === true) {
            return true;
        }

        if ($panorama === false) {
            return false;
        }

        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null || $height === 0) {
            return false;
        }

        $ratio = (float) $width / (float) $height;

        return $ratio >= 2.2;
    }

    private function isGroupScene(int $faceCount, ?float $coverage): bool
    {
        return FaceMetricHelper::isGroupShot($faceCount, $coverage);
    }

    private function looksLikeFood(Media $media): bool
    {
        if ($this->hasSceneTag($media, [
            'food',
            'meal',
            'cuisine',
            'dish',
            'dining',
            'restaurant',
            'kitchen',
            'coffee',
            'drink',
            'dessert',
            'breakfast',
            'lunch',
            'dinner',
            'brunch',
        ])) {
            return true;
        }

        $bag = $media->getFeatureBag();
        $kind = $bag->classificationKind();
        if ($kind !== null && $kind->value === 'food') {
            return true;
        }

        return false;
    }

    private function looksLikeLandmark(Media $media, array $params): bool
    {
        if ($this->hasSceneTag($media, [
            'landmark',
            'monument',
            'castle',
            'temple',
            'bridge',
            'tower',
            'cathedral',
            'church',
            'palace',
            'statue',
            'architecture',
            'skyline',
            'historic',
            'museum',
        ])) {
            return true;
        }

        $label = $this->stringOrNull($params['poi_label'] ?? null);
        if ($label !== null) {
            $needle = strtolower($label);
            foreach ([
                'museum',
                'park',
                'castle',
                'cathedral',
                'temple',
                'monument',
                'bridge',
                'square',
                'tower',
                'palace',
            ] as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isNightScene(Media $media): bool
    {
        $bag = $media->getFeatureBag();
        $daypart = $bag->calendarDaypart();
        if ($daypart === 'night') {
            return true;
        }

        if ($bag->solarIsPolarNight() === true) {
            return true;
        }

        if ($this->hasSceneTag($media, ['night', 'evening', 'dusk', 'aurora', 'milky way', 'city lights'])) {
            return true;
        }

        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            $hour = (int) $takenAt->format('H');

            return $hour >= 21 || $hour <= 5;
        }

        return false;
    }

    private function isIndoorScene(Media $media, ?string $poiBucket): bool
    {
        if ($poiBucket === 'food') {
            return true;
        }

        if ($this->hasSceneTag($media, [
            'indoor',
            'interior',
            'room',
            'kitchen',
            'living room',
            'office',
            'restaurant',
            'cafe',
            'bar',
            'museum',
            'library',
            'store',
            'shop',
            'gallery',
            'hall',
        ])) {
            return true;
        }

        return false;
    }

    private function hasSceneTag(Media $media, array $keywords): bool
    {
        $tags = $media->getSceneTags();
        if (!is_array($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            $label = $tag['label'] ?? null;
            if (!is_string($label) || $label === '') {
                continue;
            }

            $normalised = strtolower($label);
            foreach ($keywords as $keyword) {
                if ($keyword === '') {
                    continue;
                }

                if (str_contains($normalised, strtolower($keyword))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function poiMatchesFood(?string $categoryKey, ?string $categoryValue, array $tags, ?string $label): bool
    {
        $key   = $categoryKey !== null ? strtolower($categoryKey) : null;
        $value = $categoryValue !== null ? strtolower($categoryValue) : null;

        if ($key === 'amenity' && in_array($value, ['restaurant', 'cafe', 'bar', 'food_court', 'fast_food'], true)) {
            return true;
        }

        if ($key === 'shop' && in_array($value, ['bakery', 'confectionery'], true)) {
            return true;
        }

        if (array_key_exists('cuisine', $tags)) {
            return true;
        }

        if ($label !== null) {
            $needle = strtolower($label);
            foreach (['restaurant', 'café', 'cafe', 'diner', 'kitchen', 'bistro', 'bar', 'coffee'] as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function poiMatchesLandmark(?string $categoryKey, ?string $categoryValue, array $tags, ?string $label): bool
    {
        $key   = $categoryKey !== null ? strtolower($categoryKey) : null;
        $value = $categoryValue !== null ? strtolower($categoryValue) : null;

        if ($key === 'tourism' && in_array($value, ['attraction', 'viewpoint', 'museum', 'gallery', 'theme_park'], true)) {
            return true;
        }

        if ($key === 'historic' && in_array($value, ['castle', 'monument', 'ruins', 'memorial', 'church'], true)) {
            return true;
        }

        if (array_key_exists('historic', $tags) || array_key_exists('wikidata', $tags)) {
            return true;
        }

        if ($label !== null) {
            $needle = strtolower($label);
            foreach (['museum', 'monument', 'castle', 'cathedral', 'temple', 'bridge', 'tower', 'palace', 'square'] as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!is_string($entry)) {
                continue;
            }

            $result[$key] = $entry;
        }

        return $result;
    }

    private function resolveOrientationType(Media $media): ?string
    {
        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width !== null && $height !== null) {
            if ($width === $height) {
                return 'square';
            }

            return $width > $height ? 'landscape' : 'portrait';
        }

        $orientation = $media->getOrientation();
        if ($orientation === null) {
            return null;
        }

        return in_array($orientation, [5, 6, 7, 8], true) ? 'portrait' : 'landscape';
    }

    private function policySnapshot(SelectionPolicy $policy, string $storyline): array
    {
        return [
            'profile'             => $policy->getProfileKey(),
            'storyline'           => $storyline,
            'target_total'        => $policy->getTargetTotal(),
            'minimum_total'       => $policy->getMinimumTotal(),
            'max_per_day'         => $policy->getMaxPerDay(),
            'time_slot_hours'     => $policy->getTimeSlotHours(),
            'min_spacing_seconds' => $policy->getMinSpacingSeconds(),
            'phash_min_hamming'   => $policy->getPhashMinHamming(),
            'max_per_staypoint'   => $policy->getMaxPerStaypoint(),
            'relaxed_max_per_staypoint' => $policy->getRelaxedMaxPerStaypoint(),
            'quality_floor'       => $policy->getQualityFloor(),
            'video_bonus'         => $policy->getVideoBonus(),
            'face_bonus'          => $policy->getFaceBonus(),
            'selfie_penalty'      => $policy->getSelfiePenalty(),
            'max_per_year'        => $policy->getMaxPerYear(),
            'max_per_bucket'      => $policy->getMaxPerBucket(),
            'video_heavy_bonus'   => $policy->getVideoHeavyBonus(),
            'scene_bucket_weights'=> $policy->getSceneBucketWeights(),
            'core_day_bonus'      => $policy->getCoreDayBonus(),
            'peripheral_day_penalty' => $policy->getPeripheralDayPenalty(),
            'peripheral_day_max_total' => $policy->getPeripheralDayMaxTotal(),
            'peripheral_day_hard_cap' => $policy->getPeripheralDayHardCap(),
            'phash_percentile'    => $policy->getPhashPercentile(),
            'spacing_progress_factor' => $policy->getSpacingProgressFactor(),
            'cohort_penalty'      => $policy->getCohortPenalty(),
            'day_quotas'          => $policy->getDayQuotas(),
            'day_context'         => $policy->getDayContext(),
        ];
    }
}
