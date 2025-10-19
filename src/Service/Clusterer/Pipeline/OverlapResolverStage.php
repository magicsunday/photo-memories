<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function abs;
use function array_diff;
use function array_filter;
use function array_fill;
use function array_intersect;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function cos;
use function deg2rad;
use function floor;
use function hexdec;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sin;
use function sort;
use function sqrt;
use function str_contains;
use function strtolower;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Resolves strongly overlapping clusters by keeping the preferred candidate.
 */
final class OverlapResolverStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;
    use ClusterPriorityResolverTrait;

    private const MERGE_MIN_TEMPORAL_IOU         = 0.55;
    private const MERGE_MAX_SPATIAL_DISTANCE_M   = 25_000.0;
    private const MERGE_MIN_CORE_SIMILARITY      = 0.5;
    private const MERGE_MAX_PHASH_DELTA          = 0.18;
    private const MERGE_MAX_SCORE_GAP_RATIO      = 0.35;

    /** @var array<string, int> */
    private array $priorityMap = [];

    /**
     * @param list<string> $keepOrder
     */
    public function __construct(
        private readonly float $mergeThreshold,
        private readonly float $dropThreshold,
        array $keepOrder,
        private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
        if ($this->mergeThreshold <= 0.0 || $this->mergeThreshold > 1.0) {
            throw new InvalidArgumentException('mergeThreshold must be between 0 and 1.');
        }

        if ($this->dropThreshold < $this->mergeThreshold || $this->dropThreshold > 1.0) {
            throw new InvalidArgumentException('dropThreshold must be >= mergeThreshold and <= 1.');
        }

        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Überlappungen';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total              = count($drafts);
        $resolvedDrops      = 0;

        $this->emitMonitoring('selection_start', [
            'pre_count'       => $total,
            'merge_threshold' => $this->mergeThreshold,
            'drop_threshold'  => $this->dropThreshold,
            'order_count'     => count($this->priorityMap),
        ]);

        if ($total <= 1) {
            if ($progress !== null) {
                $progress($total, $total);
            }

            $this->emitMonitoring('selection_completed', [
                'pre_count'        => $total,
                'post_count'       => $total,
                'dropped_count'    => 0,
                'resolved_drops'   => 0,
            ]);

            return $drafts;
        }

        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<list<int>> $normalized */
        $normalized = array_map(
            fn (ClusterDraft $draft): array => $this->normalizeMembers($draft->getMembers()),
            $drafts,
        );

        /** @var list<bool> $keep */
        $keep = array_fill(0, $total, true);

        for ($i = 0; $i < $total; ++$i) {
            if (!$keep[$i]) {
                continue;
            }

            if ($this->isSubStory($drafts[$i])) {
                continue;
            }

            for ($j = $i + 1; $j < $total; ++$j) {
                if (!$keep[$j]) {
                    continue;
                }

                if ($this->isSubStory($drafts[$j])) {
                    continue;
                }

                $overlap = $this->jaccard($normalized[$i], $normalized[$j]);
                if ($overlap < $this->mergeThreshold) {
                    continue;
                }

                $requiresResolution = $overlap >= $this->dropThreshold
                    || $drafts[$i]->getAlgorithm() === $drafts[$j]->getAlgorithm();

                if (!$requiresResolution) {
                    continue;
                }

                $preferLeft = $this->preferLeft(
                    $drafts[$i],
                    $normalized[$i],
                    $drafts[$j],
                    $normalized[$j],
                    $this->priorityMap,
                );

                $winnerIndex = $preferLeft ? $i : $j;
                $loserIndex  = $preferLeft ? $j : $i;

                $decision = $this->resolveDecision(
                    $drafts[$winnerIndex],
                    $normalized[$winnerIndex],
                    $drafts[$loserIndex],
                    $normalized[$loserIndex],
                    $overlap,
                );

                $winner = $decision['winner'];
                $loser  = $decision['loser'];

                $drafts[$winnerIndex]    = $winner['draft'];
                $normalized[$winnerIndex] = $winner['normalized'];
                $drafts[$loserIndex]     = $loser['draft'];

                if ($keep[$loserIndex]) {
                    $keep[$loserIndex] = false;
                    ++$resolvedDrops;
                }

                if ($loserIndex === $i) {
                    break;
                }
            }

            if ($progress !== null && ($i % 200) === 0) {
                $progress($i, $total);
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        /** @var list<ClusterDraft> $result */
        $result = array_values(array_filter(
            $drafts,
            static fn (ClusterDraft $draft, int $index) => $keep[$index] ?? false,
            ARRAY_FILTER_USE_BOTH,
        ));

        $postCount = count($result);

        $this->emitMonitoring('selection_completed', [
            'pre_count'       => $total,
            'post_count'      => $postCount,
            'dropped_count'   => max(0, $total - $postCount),
            'resolved_drops'  => $resolvedDrops,
        ]);

        return $result;
    }

    /**
     * @param array<string, int|float> $payload
     */
    private function emitMonitoring(string $event, array $payload): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('overlap_resolver', $event, $payload);
    }

    /**
     * @param list<int> $winnerMembers
     * @param list<int> $loserMembers
     * @param list<int> $winnerNormalized
     * @param list<int> $loserNormalized
     *
     * @return array{winner: array{draft: ClusterDraft, normalized: list<int>}, loser: array{draft: ClusterDraft}}
     */
    private function resolveDecision(
        ClusterDraft $winner,
        array $winnerNormalized,
        ClusterDraft $loser,
        array $loserNormalized,
        float $memberIou,
    ): array {
        $metrics = $this->collectMetrics($winner, $loser, $memberIou);

        $winnerLog = $this->createDecisionLog('winner', $winner, $loser, $metrics);
        $loserLog  = $this->createDecisionLog('loser', $winner, $loser, $metrics);

        if ($this->shouldMerge($metrics)) {
            $mergedMembers   = $this->mergeMemberLists($winner->getMembers(), $loser->getMembers());
            $mergedParams    = $this->mergeParams($winner, $loser, $metrics);
            $winnerWithMerge = $winner->withMembers($mergedMembers, $mergedParams);
            $winnerWithMerge = $this->appendMergeMeta($winnerWithMerge, $winnerLog + ['decision' => 'merge']);

            $loserWithMeta = $this->appendMergeMeta($loser, $loserLog + ['decision' => 'merged_into']);

            return [
                'winner' => [
                    'draft'      => $winnerWithMerge,
                    'normalized' => $this->normalizeMembers($mergedMembers),
                ],
                'loser' => [
                    'draft' => $loserWithMeta,
                ],
            ];
        }

        $winnerWithMeta = $this->appendMergeMeta($winner, $winnerLog + ['decision' => 'dedupe']);
        $loserWithMeta  = $this->appendMergeMeta($loser, $loserLog + ['decision' => 'dedupe_dropped', 'dropped' => true]);

        return [
            'winner' => [
                'draft'      => $winnerWithMeta,
                'normalized' => $winnerNormalized,
            ],
            'loser' => [
                'draft' => $loserWithMeta,
            ],
        ];
    }

    /**
     * @return array{
     *     member_iou: float,
     *     temporal_iou: float,
     *     spatial_distance_m: float|null,
     *     core_similarity: float|null,
     *     phash_delta: float|null,
     *     score_winner: float,
     *     score_loser: float,
     *     score_gap: float,
     * }
     */
    private function collectMetrics(ClusterDraft $winner, ClusterDraft $loser, float $memberIou): array
    {
        $winnerScore = $this->computeScore($winner);
        $loserScore  = $this->computeScore($loser);

        $temporalIou = $this->computeTemporalIou($winner, $loser);
        $spatialDist = $this->computeSpatialDistance($winner, $loser);
        $coreSim     = $this->computeCoreGroupSimilarity($winner, $loser);
        $phashDelta  = $this->computePhashDelta($winner, $loser);

        $scoreGap = abs($winnerScore - $loserScore);

        return [
            'member_iou'         => $memberIou,
            'temporal_iou'       => $temporalIou,
            'spatial_distance_m' => $spatialDist,
            'core_similarity'    => $coreSim,
            'phash_delta'        => $phashDelta,
            'score_winner'       => $winnerScore,
            'score_loser'        => $loserScore,
            'score_gap'          => $scoreGap,
        ];
    }

    /**
     * @param array<string, float|int|bool|string|array|null> $metrics
     */
    private function shouldMerge(array $metrics): bool
    {
        if ($metrics['temporal_iou'] < self::MERGE_MIN_TEMPORAL_IOU) {
            return false;
        }

        $spatial = $metrics['spatial_distance_m'];
        if ($spatial !== null && $spatial > self::MERGE_MAX_SPATIAL_DISTANCE_M) {
            return false;
        }

        $core = $metrics['core_similarity'];
        if ($core !== null && $core < self::MERGE_MIN_CORE_SIMILARITY) {
            return false;
        }

        $phash = $metrics['phash_delta'];
        if ($phash !== null && $phash > self::MERGE_MAX_PHASH_DELTA) {
            return false;
        }

        $winnerScore = (float) $metrics['score_winner'];
        $scoreGap    = (float) $metrics['score_gap'];
        $maxGap      = max(1.0, $winnerScore) * self::MERGE_MAX_SCORE_GAP_RATIO;
        if ($scoreGap > $maxGap) {
            return false;
        }

        return true;
    }

    private function computeTemporalIou(ClusterDraft $a, ClusterDraft $b): float
    {
        $rangeA = $a->getParams()['time_range'] ?? null;
        $rangeB = $b->getParams()['time_range'] ?? null;

        if (!is_array($rangeA) || !is_array($rangeB)) {
            return 0.0;
        }

        $fromA = isset($rangeA['from']) ? (int) $rangeA['from'] : null;
        $toA   = isset($rangeA['to']) ? (int) $rangeA['to'] : null;
        $fromB = isset($rangeB['from']) ? (int) $rangeB['from'] : null;
        $toB   = isset($rangeB['to']) ? (int) $rangeB['to'] : null;

        if ($fromA === null || $toA === null || $fromB === null || $toB === null) {
            return 0.0;
        }

        $intersectionStart = max($fromA, $fromB);
        $intersectionEnd   = min($toA, $toB);

        if ($intersectionEnd <= $intersectionStart) {
            return 0.0;
        }

        $intersection = (float) ($intersectionEnd - $intersectionStart);
        $unionStart   = min($fromA, $fromB);
        $unionEnd     = max($toA, $toB);
        $union        = (float) ($unionEnd - $unionStart);

        if ($union <= 0.0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    private function computeSpatialDistance(ClusterDraft $a, ClusterDraft $b): ?float
    {
        $stayA = $this->extractPrimaryStaypoint($a);
        $stayB = $this->extractPrimaryStaypoint($b);

        if ($stayA !== null && $stayB !== null) {
            return $this->haversineDistance($stayA['lat'], $stayA['lon'], $stayB['lat'], $stayB['lon']);
        }

        $centroidA = $a->getCentroid();
        $centroidB = $b->getCentroid();

        if (isset($centroidA['lat'], $centroidA['lon'], $centroidB['lat'], $centroidB['lon'])) {
            return $this->haversineDistance(
                (float) $centroidA['lat'],
                (float) $centroidA['lon'],
                (float) $centroidB['lat'],
                (float) $centroidB['lon'],
            );
        }

        return null;
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function extractPrimaryStaypoint(ClusterDraft $draft): ?array
    {
        $params = $draft->getParams();
        $stay   = $params['primaryStaypoint'] ?? null;

        if (!is_array($stay)) {
            return null;
        }

        if (!isset($stay['lat'], $stay['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $stay['lat'],
            'lon' => (float) $stay['lon'],
        ];
    }

    private function computeCoreGroupSimilarity(ClusterDraft $a, ClusterDraft $b): ?float
    {
        $coreA = $this->extractCoreGroupValues($a);
        $coreB = $this->extractCoreGroupValues($b);

        if ($coreA === [] || $coreB === []) {
            return null;
        }

        $intersection = count(array_intersect($coreA, $coreB));
        $union        = count(array_unique(array_merge($coreA, $coreB)));

        if ($union === 0) {
            return null;
        }

        return $intersection / $union;
    }

    /**
     * @return list<string>
     */
    private function extractCoreGroupValues(ClusterDraft $draft): array
    {
        $params = $draft->getParams();
        $values = [];

        $candidateKeys = [
            'primaryStaypointCity',
            'primaryStaypointRegion',
            'primaryStaypointCountry',
            'place_city',
            'place_region',
            'place_country',
            'place_location',
            'people_primary_subject',
            'people_primary_relation',
        ];

        foreach ($candidateKeys as $key) {
            $value = $params[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $values[] = strtolower($value);
            }
        }

        foreach ($params as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            if (str_contains($key, 'people') || str_contains($key, 'place')) {
                $values[] = strtolower($value);
            }
        }

        $selection = $params['member_selection'] ?? null;
        if (is_array($selection)) {
            $summary = $selection['summary'] ?? null;
            if (is_array($summary)) {
                $core = $summary['core_members'] ?? null;
                if (is_array($core)) {
                    foreach ($core as $entry) {
                        if (is_string($entry) && $entry !== '') {
                            $values[] = strtolower($entry);
                        }
                    }
                }
            }
        }

        sort($values);

        return array_values(array_unique($values));
    }

    private function computePhashDelta(ClusterDraft $a, ClusterDraft $b): ?float
    {
        $medianA = $this->extractPhashMedian($a);
        $medianB = $this->extractPhashMedian($b);

        if ($medianA === null || $medianB === null) {
            return null;
        }

        $difference = abs($medianA - $medianB);

        return $difference / 0xFFFFFFFFFFFFFFFF;
    }

    private function extractPhashMedian(ClusterDraft $draft): ?int
    {
        $params = $draft->getParams();
        $selection = $params['member_selection'] ?? null;
        if (!is_array($selection)) {
            return null;
        }

        $hashSamples = $selection['hash_samples'] ?? null;
        if (!is_array($hashSamples) || $hashSamples === []) {
            return null;
        }

        $values = [];
        foreach ($hashSamples as $hash) {
            if (!is_string($hash) || $hash === '') {
                continue;
            }

            $normalized = strtolower(substr($hash, 0, 16));
            $values[]   = hexdec($normalized);
        }

        if ($values === []) {
            return null;
        }

        sort($values);

        $count = count($values);
        $middle = (int) floor($count / 2);

        if (($count % 2) === 1) {
            return (int) $values[$middle];
        }

        $left  = (float) $values[$middle - 1];
        $right = (float) $values[$middle];

        return (int) (($left + $right) / 2.0);
    }

    private function mergeParams(ClusterDraft $winner, ClusterDraft $loser, array $metrics): array
    {
        $winnerParams = $winner->getParams();
        $loserParams  = $loser->getParams();

        $merged = $winnerParams;

        $loserTime = $loserParams['time_range'] ?? null;
        $winnerTime = $winnerParams['time_range'] ?? null;
        if (
            is_array($loserTime)
            && is_array($winnerTime)
            && isset($loserTime['from'], $loserTime['to'], $winnerTime['from'], $winnerTime['to'])
        ) {
            $merged['time_range'] = [
                'from' => min((int) $winnerTime['from'], (int) $loserTime['from']),
                'to'   => max((int) $winnerTime['to'], (int) $loserTime['to']),
            ];
        }

        if (!isset($merged['primaryStaypoint'])) {
            $stay = $loserParams['primaryStaypoint'] ?? null;
            if (is_array($stay)) {
                $merged['primaryStaypoint'] = $stay;
            }
        }

        if (!isset($merged['member_selection'])) {
            $selection = $loserParams['member_selection'] ?? null;
            if (is_array($selection)) {
                $merged['member_selection'] = $selection;
            }
        } else {
            $existingSelection = $merged['member_selection'];
            $loserSelection    = $loserParams['member_selection'] ?? null;
            if (is_array($existingSelection) && is_array($loserSelection)) {
                $merged['member_selection'] = $this->mergeMemberSelection($existingSelection, $loserSelection);
            }
        }

        if (isset($loserParams['score']) && (!isset($merged['score']) || (float) $loserParams['score'] > (float) ($merged['score'] ?? 0.0))) {
            $merged['score'] = $loserParams['score'];
        }

        $merged['meta'] = $this->mergeMetaParams($winnerParams['meta'] ?? null, $loserParams['meta'] ?? null, $metrics);

        return $merged;
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<string, mixed>|null $secondary
     */
    private function mergeMetaParams(?array $primary, ?array $secondary, array $metrics): array
    {
        $meta = $primary ?? [];

        if ($secondary !== null) {
            foreach ($secondary as $key => $value) {
                if ($key === 'merges') {
                    continue;
                }

                if (!array_key_exists($key, $meta)) {
                    $meta[$key] = $value;
                }
            }
        }

        $meta['last_merge'] = [
            'temporal_iou' => $metrics['temporal_iou'],
            'core_similarity' => $metrics['core_similarity'],
            'phash_delta' => $metrics['phash_delta'],
        ];

        return $meta;
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     *
     * @return array<string, mixed>
     */
    private function mergeMemberSelection(array $primary, array $secondary): array
    {
        if (!isset($primary['hash_samples'])) {
            $primary['hash_samples'] = $secondary['hash_samples'] ?? [];
        } else {
            $primaryHashes = is_array($primary['hash_samples']) ? $primary['hash_samples'] : [];
            $secondaryHashes = is_array($secondary['hash_samples'] ?? null) ? $secondary['hash_samples'] : [];
            if ($secondaryHashes !== []) {
                $primary['hash_samples'] = $this->mergeHashSamples($primaryHashes, $secondaryHashes);
            }
        }

        return $primary;
    }

    /**
     * @param array<int|string, string> $primary
     * @param array<int|string, string> $secondary
     *
     * @return array<int|string, string>
     */
    private function mergeHashSamples(array $primary, array $secondary): array
    {
        $result = $primary;

        foreach ($secondary as $key => $value) {
            if (is_string($value) && $value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        if (count($result) > 12) {
            $result = array_slice($result, 0, 12);
        }

        return $result;
    }

    /**
     * @param list<int> $winnerMembers
     * @param list<int> $loserMembers
     *
     * @return list<int>
     */
    private function mergeMemberLists(array $winnerMembers, array $loserMembers): array
    {
        $merged = $winnerMembers;
        $diff   = array_diff($loserMembers, $winnerMembers);

        foreach ($diff as $member) {
            $merged[] = $member;
        }

        return $merged;
    }

    private function haversineDistance(float $latA, float $lonA, float $latB, float $lonB): float
    {
        $earthRadius = 6_371_000.0;

        $deltaLat = deg2rad($latB - $latA);
        $deltaLon = deg2rad($lonB - $lonA);

        $lat1 = deg2rad($latA);
        $lat2 = deg2rad($latB);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $c = 2 * asin(min(1.0, sqrt($a)));

        return $earthRadius * $c;
    }

    /**
     * @param array<string, mixed> $metrics
     *
     * @return array<string, mixed>
     */
    private function createDecisionLog(string $role, ClusterDraft $winner, ClusterDraft $loser, array $metrics): array
    {
        $fingerprintWinner = $this->fingerprint($this->normalizeMembers($winner->getMembers()));
        $fingerprintLoser  = $this->fingerprint($this->normalizeMembers($loser->getMembers()));

        return [
            'role'       => $role,
            'winner'     => [
                'algorithm'   => $winner->getAlgorithm(),
                'fingerprint' => $fingerprintWinner,
                'score'       => $metrics['score_winner'],
            ],
            'contender'  => [
                'algorithm'   => $loser->getAlgorithm(),
                'fingerprint' => $fingerprintLoser,
                'score'       => $metrics['score_loser'],
            ],
            'metrics'    => [
                'member_iou'         => $metrics['member_iou'],
                'temporal_iou'       => $metrics['temporal_iou'],
                'spatial_distance_m' => $metrics['spatial_distance_m'],
                'core_similarity'    => $metrics['core_similarity'],
                'phash_delta'        => $metrics['phash_delta'],
                'score_gap'          => $metrics['score_gap'],
            ],
        ];
    }

    private function appendMergeMeta(ClusterDraft $draft, array $entry): ClusterDraft
    {
        $params = $draft->getParams();
        $meta   = $params['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $merges = $meta['merges'] ?? [];
        if (!is_array($merges)) {
            $merges = [];
        }

        $merges[]    = $entry;
        $meta['merges'] = $merges;
        $params['meta'] = $meta;

        return $draft->withParams($params);
    }
}
