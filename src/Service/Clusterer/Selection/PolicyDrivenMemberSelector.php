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
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_key_exists;
use function array_map;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function floor;
use function hexdec;
use function in_array;
use function is_string;
use function ksort;
use function max;
use function min;
use function sort;
use function strtolower;
use function substr;
use function strlen;
use function usort;

use const SORT_NUMERIC;

/**
 * Policy driven implementation combining greedy pre-filtering and max-min diversification.
 */
final class PolicyDrivenMemberSelector implements ClusterMemberSelectorInterface
{
    private const STAYPOINT_MERGE_METERS = 120.0;

    private const SLOT_CAP = 2;

    private const PERSON_REPEAT_PENALTY = 0.12;

    private const MIN_RELAXED_SPACING = 25;

    /** @var array<string, list<int>> */
    private array $phashCache = [];

    /** @var array<string, float> */
    private array $hammingCache = [];

    /** @var array<string, float|null> */
    private array $distanceCache = [];

    public function select(string $algorithm, array $memberIds, ?MemberSelectionContext $context = null): MemberSelectionResult
    {
        if ($context === null) {
            throw new InvalidArgumentException('MemberSelectionContext is required for policy driven selection.');
        }

        $this->phashCache   = [];
        $this->hammingCache = [];
        $this->distanceCache = [];

        $policy   = $context->getPolicy();
        $draft    = $context->getDraft();
        $mediaMap = $context->getMediaMap();

        if ($memberIds === []) {
            return new MemberSelectionResult([], [
                'counts' => [
                    'considered' => 0,
                    'greedy'     => 0,
                    'diversified'=> 0,
                ],
                'policy' => $this->policySnapshot($policy),
            ]);
        }

        $qualityScores = $context->getQualityScores();

        $candidates = $this->buildCandidates($memberIds, $mediaMap, $qualityScores, $policy, $draft);

        $telemetry = [
            'counts' => [
                'considered' => count($candidates['all']),
                'eligible'   => 0,
                'greedy'     => 0,
                'diversified'=> 0,
            ],
            'drops' => [
                'no_show'        => $candidates['drops']['no_show'],
                'quality'        => $candidates['drops']['quality'],
                'burst'          => 0,
                'slot'           => 0,
                'spacing'        => 0,
                'near_duplicate' => 0,
                'staypoint'      => 0,
            ],
            'relaxations' => [],
            'distribution' => [
                'per_day'   => [],
                'per_year'  => [],
                'per_bucket'=> [],
            ],
            'distance_samples' => [
                'phash' => [],
                'time'  => [],
            ],
            'policy' => $this->policySnapshot($policy),
        ];

        $eligibleCandidates = $candidates['eligible'];
        $telemetry['counts']['eligible'] = count($eligibleCandidates);

        if ($eligibleCandidates === []) {
            return new MemberSelectionResult([], $telemetry);
        }

        $relaxations = [];
        $greedy      = [];
        $appliedPolicy = $policy;

        $attempts = [
            static fn (SelectionPolicy $p): SelectionPolicy => $p,
            static fn (SelectionPolicy $p): SelectionPolicy => $p->withRelaxedSpacing(
                max(self::MIN_RELAXED_SPACING, (int) floor($p->getMinSpacingSeconds() * 0.6))
            ),
            static fn (SelectionPolicy $p): SelectionPolicy => $p->withRelaxedHamming(
                max(8, (int) floor($p->getPhashMinHamming() * 0.75))
            ),
            static fn (SelectionPolicy $p): SelectionPolicy => $p->withoutCaps(),
        ];

        foreach ($attempts as $idx => $mutator) {
            $candidatePolicy = $mutator($policy);
            $greedy          = $this->runGreedy($eligibleCandidates, $candidatePolicy, $telemetry);

            if (count($greedy) >= $policy->getMinimumTotal() || $idx === (count($attempts) - 1)) {
                $appliedPolicy = $candidatePolicy;
                break;
            }

            $relaxations[] = [
                'step'    => $idx,
                'members' => count($greedy),
                'policy'  => $this->policySnapshot($candidatePolicy),
            ];
        }

        $telemetry['counts']['greedy'] = count($greedy);
        if ($relaxations !== []) {
            $telemetry['relaxations'] = $relaxations;
            $telemetry['policy']      = $this->policySnapshot($appliedPolicy);
        }

        if ($greedy === []) {
            return new MemberSelectionResult([], $telemetry);
        }

        $diversified = $this->runDiversifier($greedy, $appliedPolicy, $telemetry);
        $telemetry['counts']['diversified'] = count($diversified);

        $perDay    = [];
        $perYear   = [];
        $perBucket = [];
        $timeSamples = [];
        $hashSamples = [];
        $lastTimestamp = null;

        foreach ($diversified as $candidate) {
            $perDay[$candidate['day']] = ($perDay[$candidate['day']] ?? 0) + 1;
            $perYear[$candidate['year']] = ($perYear[$candidate['year']] ?? 0) + 1;
            $perBucket[$candidate['bucket']] = ($perBucket[$candidate['bucket']] ?? 0) + 1;

            if ($lastTimestamp !== null) {
                $timeSamples[] = abs($candidate['timestamp'] - $lastTimestamp);
            }

            $lastTimestamp = $candidate['timestamp'];
        }

        ksort($perDay);
        ksort($perYear, SORT_NUMERIC);
        ksort($perBucket);

        $telemetry['distribution']['per_day']    = $perDay;
        $telemetry['distribution']['per_year']   = $perYear;
        $telemetry['distribution']['per_bucket'] = $perBucket;

        if ($timeSamples !== []) {
            $telemetry['distance_samples']['time'] = $timeSamples;
        }

        if ($telemetry['distance_samples']['phash'] !== []) {
            sort($telemetry['distance_samples']['phash'], SORT_NUMERIC);
        }

        $memberIds = array_map(static fn (array $candidate): int => $candidate['id'], $diversified);

        return new MemberSelectionResult($memberIds, $telemetry);
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
    ): array {
        $eligible = [];
        $drops    = [
            'no_show' => 0,
            'quality' => 0,
        ];

        $staypointCenters = [];
        $all              = [];

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

            $dayKey   = $this->formatDay($media, $timestamp);
            $slotKey  = $this->resolveSlot($media, $timestamp, $policy);
            $stayId   = $this->assignStaypoint($media, $staypointCenters);
            $isVideo  = $media->isVideo();
            $persons  = $media->getPersons();
            $hasFaces = $media->hasFaces();
            $year     = (int) (new DateTimeImmutable('@' . $timestamp))->format('Y');
            $bucket   = $this->resolveBucket($draft, $media, $dayKey);

            $score = $quality;
            if ($isVideo) {
                $score += $policy->getVideoBonus();
            }

            if ($hasFaces) {
                $score += $policy->getFaceBonus();
            }

            if ($this->isLikelySelfie($media) && $policy->getSelfiePenalty() > 0.0) {
                $score -= $policy->getSelfiePenalty();
            }

            $hashBits = $this->decodeHash($media);

            $eligible[] = [
                'id'         => $id,
                'media'      => $media,
                'timestamp'  => $timestamp,
                'day'        => $dayKey,
                'slot'       => $slotKey,
                'staypoint'  => $stayId,
                'score'      => max(0.0, $score),
                'quality'    => $quality,
                'is_video'   => $isVideo,
                'persons'    => $persons ?? [],
                'has_faces'  => $hasFaces,
                'hash_bits'  => $hashBits,
                'year'       => $year,
                'bucket'     => $bucket,
                'burst'      => $media->getBurstUuid(),
            ];
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
     *
     * @return list<array<string, mixed>>
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

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return list<array<string, mixed>>
     */
    private function runGreedy(array $candidates, SelectionPolicy $policy, array &$telemetry): array
    {
        $selected             = [];
        $countByDay           = [];
        $countByStaypoint     = [];
        $countBySlot          = [];
        $lastTimestampGlobal  = null;
        $lastTimestampPerDay  = [];
        $lastPersons          = [];
        $phashThreshold       = $policy->getPhashMinHamming();
        $minSpacing           = $policy->getMinSpacingSeconds();
        $maxPerDay            = $policy->getMaxPerDay();
        $maxPerStaypoint      = $policy->getMaxPerStaypoint();
        $videoHeavyBonus      = $policy->getVideoHeavyBonus();
        $videoShare           = $this->calculateVideoShare($candidates);
        $extraVideoBonus      = ($videoHeavyBonus !== null && $videoShare >= 0.5) ? $videoHeavyBonus : 0.0;

        foreach ($candidates as $candidate) {
            $score = $candidate['score'] + ($candidate['is_video'] ? $extraVideoBonus : 0.0);
            if ($lastPersons !== [] && $candidate['persons'] !== [] && $candidate['persons'] === $lastPersons) {
                $score -= self::PERSON_REPEAT_PENALTY;
            }

            $candidate['score'] = $score;
            $day                = $candidate['day'];
            $timestamp          = $candidate['timestamp'];
            $slot               = $candidate['slot'];
            $staypoint          = $candidate['staypoint'];

            if ($maxPerDay !== null && ($countByDay[$day] ?? 0) >= $maxPerDay) {
                ++$telemetry['drops']['slot'];
                continue;
            }

            if ($slot !== null) {
                $slotKey = $day . '#' . $slot;
                if (($countBySlot[$slotKey] ?? 0) >= self::SLOT_CAP) {
                    ++$telemetry['drops']['slot'];
                    continue;
                }
            }

            if ($lastTimestampGlobal !== null && abs($timestamp - $lastTimestampGlobal) < $minSpacing) {
                ++$telemetry['drops']['spacing'];
                continue;
            }

            if (array_key_exists($day, $lastTimestampPerDay) && abs($timestamp - $lastTimestampPerDay[$day]) < $minSpacing) {
                ++$telemetry['drops']['spacing'];
                continue;
            }

            if ($maxPerStaypoint !== null && $staypoint !== null && ($countByStaypoint[$staypoint] ?? 0) >= $maxPerStaypoint) {
                ++$telemetry['drops']['staypoint'];
                continue;
            }

            $nearDuplicate = false;
            foreach ($selected as $index => $existing) {
                $distance = $this->computeHamming($candidate, $existing);
                if ($distance !== null && $distance < $phashThreshold) {
                    $nearDuplicate = true;
                    if ($candidate['score'] > $existing['score']) {
                        $selected[$index] = $candidate;
                        $lastTimestampPerDay[$day] = $timestamp;
                        $lastTimestampGlobal      = $timestamp;
                        $countByDay[$day]         = ($countByDay[$day] ?? 0) + 1;
                        if ($staypoint !== null) {
                            $countByStaypoint[$staypoint] = ($countByStaypoint[$staypoint] ?? 0) + 1;
                        }
                        if ($slot !== null) {
                            $slotKey = $day . '#' . $slot;
                            $countBySlot[$slotKey] = ($countBySlot[$slotKey] ?? 0) + 1;
                        }
                    }

                    ++$telemetry['drops']['near_duplicate'];
                    break;
                }
            }

            if ($nearDuplicate) {
                continue;
            }

            $selected[] = $candidate;
            $lastTimestampPerDay[$day] = $timestamp;
            $lastTimestampGlobal       = $timestamp;
            $countByDay[$day]          = ($countByDay[$day] ?? 0) + 1;
            if ($staypoint !== null) {
                $countByStaypoint[$staypoint] = ($countByStaypoint[$staypoint] ?? 0) + 1;
            }

            if ($slot !== null) {
                $slotKey = $day . '#' . $slot;
                $countBySlot[$slotKey] = ($countBySlot[$slotKey] ?? 0) + 1;
            }

            $lastPersons = $candidate['persons'];
        }

        usort($selected, static function (array $a, array $b): int {
            if ($a['timestamp'] === $b['timestamp']) {
                return $a['id'] <=> $b['id'];
            }

            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return list<array<string, mixed>>
     */
    private function runDiversifier(array $candidates, SelectionPolicy $policy, array &$telemetry): array
    {
        $target       = min($policy->getTargetTotal(), count($candidates));
        $selected     = [];
        $remaining    = $candidates;
        $countByDay   = [];
        $countByStay  = [];
        $countByYear  = [];
        $countByBucket= [];

        usort($remaining, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return $a['timestamp'] <=> $b['timestamp'];
            }

            return $a['score'] < $b['score'] ? 1 : -1;
        });

        if ($remaining === []) {
            return [];
        }

        $selected[] = array_shift($remaining);
        $this->incrementCounters($selected[0], $countByDay, $countByStay, $countByYear, $countByBucket);

        while (count($selected) < $target && $remaining !== []) {
            $bestCandidate = null;
            $bestScore     = -1.0;
            $bestHamming   = null;
            $bestIndex     = null;

            foreach ($remaining as $idx => $candidate) {
                if (!$this->respectsCaps($candidate, $policy, $countByDay, $countByStay, $countByYear, $countByBucket)) {
                    continue;
                }

                $detail = $this->minDistanceDetail($candidate, $selected);
                if ($detail['score'] > $bestScore) {
                    $bestScore   = $detail['score'];
                    $bestCandidate = $candidate;
                    $bestIndex = $idx;
                    $bestHamming = $detail['hamming'];
                }
            }

            if ($bestCandidate === null) {
                break;
            }

            if ($bestHamming !== null) {
                $telemetry['distance_samples']['phash'][] = $bestHamming;
            }
            $selected[] = $bestCandidate;
            $this->incrementCounters($bestCandidate, $countByDay, $countByStay, $countByYear, $countByBucket);
            unset($remaining[$bestIndex]);
            $remaining = array_values($remaining);
        }

        usort($selected, static function (array $a, array $b): int {
            if ($a['timestamp'] === $b['timestamp']) {
                return $a['id'] <=> $b['id'];
            }

            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $selected
     *
     * @return array{score: float, hamming: int|null}
     */
    private function minDistanceDetail(array $candidate, array $selected): array
    {
        $bestScore   = -1.0;
        $bestHamming = null;

        foreach ($selected as $existing) {
            $hamming = null;
            $score   = $this->combinedDistance($candidate, $existing, $hamming);
            if ($score > $bestScore) {
                $bestScore   = $score;
                $bestHamming = $hamming;
            }
        }

        return ['score' => $bestScore, 'hamming' => $bestHamming];
    }

    private function combinedDistance(array $a, array $b, ?int &$hamming = null): float
    {
        $timeDiff = abs($a['timestamp'] - $b['timestamp']);
        $timeScore = min(1.0, $timeDiff / 600.0);

        $hashDistance = $this->computeHamming($a, $b);
        $hamming      = $hashDistance;
        $hashScore    = $hashDistance === null ? 0.5 : min(1.0, $hashDistance / 64.0);

        $geoScore = $this->geoDistanceScore($a, $b);
        $personScore = $this->personDistanceScore($a['persons'], $b['persons']);

        return ($timeScore + $hashScore + $geoScore + $personScore) / 4.0;
    }

    private function geoDistanceScore(array $a, array $b): float
    {
        $mediaA = $a['media'];
        $mediaB = $b['media'];
        $cacheKey = $mediaA->getId() . ':' . $mediaB->getId();
        if (array_key_exists($cacheKey, $this->distanceCache)) {
            $distance = $this->distanceCache[$cacheKey];
            return $distance === null ? 0.5 : min(1.0, $distance / 1000.0);
        }

        $latA = $mediaA->getGpsLat();
        $lonA = $mediaA->getGpsLon();
        $latB = $mediaB->getGpsLat();
        $lonB = $mediaB->getGpsLon();

        if ($latA === null || $lonA === null || $latB === null || $lonB === null) {
            $this->distanceCache[$cacheKey] = null;

            return 0.5;
        }

        $distance = MediaMath::haversineDistanceInMeters($latA, $lonA, $latB, $lonB);
        $this->distanceCache[$cacheKey] = $distance;

        return min(1.0, $distance / 1000.0);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function personDistanceScore(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 0.5;
        }

        $overlap = 0;
        foreach ($a as $person) {
            if (in_array($person, $b, true)) {
                ++$overlap;
            }
        }

        $union = count(array_values(array_unique([...$a, ...$b])));
        if ($union === 0) {
            return 0.5;
        }

        return 1.0 - ($overlap / $union);
    }

    private function respectsCaps(
        array $candidate,
        SelectionPolicy $policy,
        array $countByDay,
        array $countByStay,
        array $countByYear,
        array $countByBucket,
    ): bool {
        $day = $candidate['day'];
        if ($policy->getMaxPerDay() !== null && ($countByDay[$day] ?? 0) >= $policy->getMaxPerDay()) {
            return false;
        }

        $stay = $candidate['staypoint'];
        if ($policy->getMaxPerStaypoint() !== null && $stay !== null && ($countByStay[$stay] ?? 0) >= $policy->getMaxPerStaypoint()) {
            return false;
        }

        $year = $candidate['year'];
        if ($policy->getMaxPerYear() !== null && ($countByYear[$year] ?? 0) >= $policy->getMaxPerYear()) {
            return false;
        }

        $bucket = $candidate['bucket'];
        if ($policy->getMaxPerBucket() !== null && ($countByBucket[$bucket] ?? 0) >= $policy->getMaxPerBucket()) {
            return false;
        }

        return true;
    }

    private function incrementCounters(
        array $candidate,
        array &$countByDay,
        array &$countByStay,
        array &$countByYear,
        array &$countByBucket,
    ): void {
        $day = $candidate['day'];
        $countByDay[$day] = ($countByDay[$day] ?? 0) + 1;

        $stay = $candidate['staypoint'];
        if ($stay !== null) {
            $countByStay[$stay] = ($countByStay[$stay] ?? 0) + 1;
        }

        $year = $candidate['year'];
        $countByYear[$year] = ($countByYear[$year] ?? 0) + 1;

        $bucket = $candidate['bucket'];
        $countByBucket[$bucket] = ($countByBucket[$bucket] ?? 0) + 1;
    }

    private function calculateVideoShare(array $candidates): float
    {
        $total = count($candidates);
        if ($total === 0) {
            return 0.0;
        }

        $videos = 0;
        foreach ($candidates as $candidate) {
            if ($candidate['is_video']) {
                ++$videos;
            }
        }

        return $videos / $total;
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

    private function computeHamming(array $a, array $b): ?int
    {
        $hashA = $a['hash_bits'];
        $hashB = $b['hash_bits'];
        if ($hashA === null || $hashB === null) {
            return null;
        }

        $cacheKey = $a['id'] < $b['id']
            ? $a['id'] . ':' . $b['id']
            : $b['id'] . ':' . $a['id'];

        if (array_key_exists($cacheKey, $this->hammingCache)) {
            return (int) $this->hammingCache[$cacheKey];
        }

        $len = min(count($hashA), count($hashB));
        $distance = 0;
        for ($i = 0; $i < $len; ++$i) {
            if ($hashA[$i] !== $hashB[$i]) {
                ++$distance;
            }
        }

        $distance += abs(count($hashA) - count($hashB));
        $this->hammingCache[$cacheKey] = (float) $distance;

        return $distance;
    }

    private function isLikelySelfie(Media $media): bool
    {
        $persons = $media->getPersons();
        if ($persons === null) {
            return false;
        }

        return count($persons) === 1 && $media->hasFaces();
    }

    private function resolveBucket(ClusterDraft $draft, Media $media, string $day): string
    {
        $params = $draft->getParams();
        $bucketKey = $params['bucket_key'] ?? null;
        if (is_string($bucketKey) && $bucketKey !== '') {
            return $bucketKey;
        }

        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            return $takenAt->format('m-d');
        }

        return substr($day, 5);
    }

    private function policySnapshot(SelectionPolicy $policy): array
    {
        return [
            'profile'             => $policy->getProfileKey(),
            'target_total'        => $policy->getTargetTotal(),
            'minimum_total'       => $policy->getMinimumTotal(),
            'max_per_day'         => $policy->getMaxPerDay(),
            'time_slot_hours'     => $policy->getTimeSlotHours(),
            'min_spacing_seconds' => $policy->getMinSpacingSeconds(),
            'phash_min_hamming'   => $policy->getPhashMinHamming(),
            'max_per_staypoint'   => $policy->getMaxPerStaypoint(),
            'quality_floor'       => $policy->getQualityFloor(),
            'video_bonus'         => $policy->getVideoBonus(),
            'face_bonus'          => $policy->getFaceBonus(),
            'selfie_penalty'      => $policy->getSelfiePenalty(),
            'max_per_year'        => $policy->getMaxPerYear(),
            'max_per_bucket'      => $policy->getMaxPerBucket(),
            'video_heavy_bonus'   => $policy->getVideoHeavyBonus(),
        ];
    }
}
