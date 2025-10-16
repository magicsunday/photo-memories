<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\ClusterMemberSelectorInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionContext;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function arsort;
use function array_key_exists;
use function array_filter;
use function array_merge;
use function array_keys;
use function array_sum;
use function ceil;
use function count;
use function floor;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function strcmp;
use function usort;

/**
 * Stage that curates cluster member lists using the policy driven selector.
 */
final class MemberCurationStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    public function __construct(
        private readonly MemberMediaLookupInterface $mediaLookup,
        private readonly SelectionPolicyProvider $policyProvider,
        private readonly ClusterMemberSelectorInterface $selector,
        private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
    }

    public function getLabel(): string
    {
        return 'Mitgliedskuration';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);
        if ($total === 0) {
            if ($progress !== null) {
                $progress(0, 0);
            }

            return $drafts;
        }

        $result = [];
        foreach ($drafts as $index => $draft) {
            $members = $draft->getMembers();
            if ($members === []) {
                $result[] = $draft;
                if ($progress !== null) {
                    $progress($index + 1, $total);
                }

                continue;
            }

            $daySegments    = $this->extractDaySegments($draft);
            $runLengthDays  = $daySegments === [] ? null : count($daySegments);
            $policy         = $runLengthDays === null
                ? $this->policyProvider->forAlgorithm($draft->getAlgorithm(), $draft->getStoryline())
                : $this->policyProvider->forAlgorithmWithRunLength(
                    $draft->getAlgorithm(),
                    $draft->getStoryline(),
                    $runLengthDays,
                );

            if ($daySegments !== []) {
                $policy = $this->applyDayContext($policy, $daySegments);
            }
            $media  = $this->mediaLookup->findByIds($members);

            $mediaMap = [];
            foreach ($media as $entity) {
                $mediaMap[$entity->getId()] = $entity;
            }

            $qualityScores = $this->extractQualityScores($draft);
            $context       = new MemberSelectionContext($draft, $policy, $mediaMap, $qualityScores, $policy->getDayContext());

            $preCount = count($members);
            $this->emitMonitoring('selection_start', [
                'algorithm'   => $draft->getAlgorithm(),
                'storyline'   => $draft->getStoryline(),
                'pre_count'   => $preCount,
                'policy'      => $policy->getProfileKey(),
                'target_total'=> $policy->getTargetTotal(),
            ]);

            $resultSet = $this->selector->select($draft->getAlgorithm(), $members, $context);
            $curated   = $resultSet->getMemberIds();
            $postCount = count($curated);
            $telemetry = $this->augmentTelemetry($draft, $policy, $resultSet->getTelemetry(), $preCount, $postCount);

            $dropped = $preCount > $postCount ? $preCount - $postCount : 0;

            $presentation = [
                'storyline' => $draft->getStoryline(),
                'policy'    => $telemetry['policy'] ?? [],
                'counts'    => [
                    'raw'     => $preCount,
                    'curated' => $postCount,
                    'dropped' => $dropped,
                ],
                'spacing'   => [
                    'average_seconds' => (float) ($telemetry['avg_time_gap_s'] ?? 0.0),
                    'rejections'      => (int) ($telemetry['rejection_counts'][SelectionTelemetry::REASON_TIME_GAP] ?? 0),
                ],
                'hash_distance' => (float) ($telemetry['avg_phash_distance'] ?? 0.0),
                'rejection_counts' => $telemetry['rejection_counts'] ?? [],
            ];

            $params = $draft->getParams();
            $params['member_selection'] = $presentation;

            $memberQuality = $params['member_quality'] ?? [];
            if (!is_array($memberQuality)) {
                $memberQuality = [];
            }

            $memberQuality['ordered'] = $curated;

            $summary = $memberQuality['summary'] ?? [];
            if (!is_array($summary)) {
                $summary = [];
            }

            $summary = array_merge($summary, [
                'selection_counts' => [
                    'raw'     => $preCount,
                    'curated' => $postCount,
                    'dropped' => $dropped,
                ],
                'selection_per_day_distribution'    => $telemetry['per_day_distribution'] ?? [],
                'selection_per_bucket_distribution' => $telemetry['per_bucket_distribution'] ?? [],
                'selection_spacing' => [
                    'average_seconds' => (float) ($telemetry['avg_time_gap_s'] ?? 0.0),
                    'rejections'      => (int) ($telemetry['rejection_counts'][SelectionTelemetry::REASON_TIME_GAP] ?? 0),
                ],
                'selection_near_duplicates' => [
                    'blocked'      => (int) ($telemetry['rejection_counts'][SelectionTelemetry::REASON_PHASH] ?? 0),
                    'replacements' => 0,
                ],
                'selection_storyline' => $draft->getStoryline(),
                'selection_policy'    => $policy->getProfileKey(),
                'selection_telemetry' => $telemetry,
            ]);

            $memberQuality['summary'] = $summary;
            $params['member_quality']  = $memberQuality;

            $result[] = $draft->withParams($params);

            $rejections = [];
            if (isset($telemetry['rejection_counts']) && is_array($telemetry['rejection_counts'])) {
                $rejections = $telemetry['rejection_counts'];
            }

            $this->emitMonitoring('selection_completed', [
                'algorithm'              => $draft->getAlgorithm(),
                'storyline'              => $draft->getStoryline(),
                'pre_count'              => $preCount,
                'post_count'             => $postCount,
                'dropped_near_duplicates'=> (int) ($rejections[SelectionTelemetry::REASON_PHASH] ?? 0),
                'dropped_spacing'        => (int) ($rejections[SelectionTelemetry::REASON_TIME_GAP] ?? 0),
                'avg_time_gap_s'         => $telemetry['avg_time_gap_s'],
                'avg_phash_distance'     => $telemetry['avg_phash_distance'],
            ]);

            if ($progress !== null) {
                $progress($index + 1, $total);
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}>
     */
    private function extractDaySegments(ClusterDraft $draft): array
    {
        $params   = $draft->getParams();
        $segments = $params['day_segments'] ?? null;
        if (!is_array($segments)) {
            return [];
        }

        $result = [];
        foreach ($segments as $day => $info) {
            if (!is_string($day) || $day === '' || !is_array($info)) {
                continue;
            }

            $scoreRaw = $info['score'] ?? 0.0;
            if (is_float($scoreRaw) || is_int($scoreRaw)) {
                $score = (float) $scoreRaw;
            } elseif (is_string($scoreRaw) && is_numeric($scoreRaw)) {
                $score = (float) $scoreRaw;
            } else {
                $score = 0.0;
            }

            $category = $info['category'] ?? 'peripheral';
            if (!is_string($category) || $category === '') {
                $category = 'peripheral';
            }

            $durationRaw = $info['duration'] ?? null;
            $duration    = null;
            if (is_int($durationRaw) && $durationRaw >= 0) {
                $duration = $durationRaw;
            } elseif (is_string($durationRaw) && is_numeric($durationRaw)) {
                $candidate = (int) $durationRaw;
                if ($candidate >= 0) {
                    $duration = $candidate;
                }
            }

            $metricsRaw = $info['metrics'] ?? [];
            $metrics    = [];
            if (is_array($metricsRaw)) {
                foreach ($metricsRaw as $key => $value) {
                    if (!is_string($key) || $key === '') {
                        continue;
                    }

                    if (is_float($value) || is_int($value)) {
                        $metrics[$key] = (float) $value;

                        continue;
                    }

                    if (is_string($value) && is_numeric($value)) {
                        $metrics[$key] = (float) $value;
                    }
                }
            }

            $result[$day] = [
                'score'    => $score,
                'category' => $category,
                'duration' => $duration,
                'metrics'  => $metrics,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $daySegments
     */
    private function applyDayContext(SelectionPolicy $policy, array $daySegments): SelectionPolicy
    {
        if ($daySegments === []) {
            return $policy;
        }

        $dayCount    = count($daySegments);
        $targetTotal = $policy->getTargetTotal();
        $dynamicCap  = (int) ceil($targetTotal / max(1, $dayCount));
        $baseCap     = $policy->getMaxPerDay();
        if ($baseCap !== null) {
            $dynamicCap = min($baseCap, $dynamicCap);
        }

        $baseCap = max(1, $dynamicCap);

        $peripheralCount = 0;
        foreach ($daySegments as $day => $info) {
            if (!is_array($info)) {
                continue;
            }

            if (($info['category'] ?? 'peripheral') === 'peripheral') {
                ++$peripheralCount;
            }
        }

        $peripheralQuotaLimit = null;
        $peripheralHardCap    = null;
        if ($peripheralCount > 0) {
            $peripheralShare = $dayCount > 0 ? $peripheralCount / $dayCount : 0.0;
            $peripheralRatio = 0.35;
            if ($peripheralShare >= 0.6) {
                $peripheralRatio = 0.4;
            } elseif ($peripheralShare <= 0.25) {
                $peripheralRatio = 0.3;
            }

            $peripheralQuotaLimit = (int) floor($targetTotal * $peripheralRatio);
            if ($peripheralQuotaLimit < $peripheralCount) {
                $peripheralQuotaLimit = $peripheralCount;
            }

            if ($peripheralQuotaLimit > $targetTotal) {
                $peripheralQuotaLimit = $targetTotal;
            }

            if ($peripheralCount > 0) {
                $peripheralHardCap = $targetTotal >= ($peripheralCount * 2) ? 2 : 1;
            }
        }

        $dayQuotas        = [];
        $peripheralQuotas = [];
        $minimumPerDay    = [];
        foreach ($daySegments as $day => $info) {
            $quota = $baseCap;

            if ($info['category'] === 'core') {
                $quota += $policy->getCoreDayBonus();
            } elseif ($info['category'] === 'peripheral') {
                $quota -= $policy->getPeripheralDayPenalty();
            }

            if ($info['category'] === 'peripheral') {
                if ($quota < 0) {
                    $quota = 0;
                }

                if ($peripheralHardCap !== null) {
                    $quota = min($quota, $peripheralHardCap);
                }

                $peripheralQuotas[$day] = (int) $quota;
                $minimumPerDay[$day]    = 0;
            } else {
                if ($quota < 1) {
                    $quota = 1;
                }

                $minimumPerDay[$day] = 1;
            }

            $dayQuotas[$day] = (int) $quota;
        }

        if ($peripheralQuotaLimit !== null && $peripheralQuotas !== []) {
            $peripheralTotal = array_sum($peripheralQuotas);
            if ($peripheralTotal > $peripheralQuotaLimit) {
                $overflow = $peripheralTotal - $peripheralQuotaLimit;
                arsort($peripheralQuotas);

                while ($overflow > 0) {
                    $reduced = false;
                    foreach ($peripheralQuotas as $day => $quota) {
                        if ($overflow === 0) {
                            break;
                        }

                        if ($quota <= 0) {
                            continue;
                        }

                        $newQuota = max(0, $quota - 1);
                        if ($newQuota === $quota) {
                            continue;
                        }

                        $peripheralQuotas[$day] = $newQuota;
                        $overflow               -= ($quota - $newQuota);
                        $reduced                 = true;
                    }

                    if ($reduced === false) {
                        break;
                    }

                    arsort($peripheralQuotas);
                }
            }

            foreach ($peripheralQuotas as $day => $quota) {
                $dayQuotas[$day] = (int) $quota;
            }
        }

        $aggregateMinimum = array_sum($minimumPerDay);
        $targetFloor      = max($aggregateMinimum, $policy->getMinimumTotal());

        $currentTotal = array_sum($dayQuotas);
        $targetGoal   = min($targetTotal, $currentTotal);
        if ($targetGoal < $targetFloor) {
            $targetGoal = min($currentTotal, $targetFloor);
        }

        if ($currentTotal > $targetGoal) {
            $dayQuotas = $this->rebalanceDayQuotas($dayQuotas, $daySegments, $minimumPerDay, $targetGoal);
        }

        $policyWithContext = $policy->withDayContext($dayQuotas, $daySegments, $peripheralQuotaLimit, $peripheralHardCap);

        $staypointCap = $policyWithContext->getMaxPerStaypoint();
        if ($staypointCap !== null) {
            $staypointBase = $dayQuotas !== [] ? max($dayQuotas) : $policy->getMaxPerDay();

            if ($staypointBase !== null && $staypointBase > 0) {
                $dynamicCap = max(1, (int) floor($staypointBase / 2));
                $targetCap  = $staypointCap <= $dynamicCap ? $staypointCap : $dynamicCap;
                if ($targetCap !== $staypointCap) {
                    $policyWithContext = $policyWithContext->withMaxPerStaypoint($targetCap);
                }
            }
        }

        return $policyWithContext;
    }

    /**
     * @param array<string, int> $dayQuotas
     * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $daySegments
     * @param array<string, int> $minimumPerDay
     *
     * @return array<string, int>
     */
    private function rebalanceDayQuotas(array $dayQuotas, array $daySegments, array $minimumPerDay, int $targetGoal): array
    {
        $excess = array_sum($dayQuotas) - $targetGoal;
        if ($excess <= 0) {
            return $dayQuotas;
        }

        $peripheralDays = array_keys(array_filter($daySegments, static fn (array $info): bool => ($info['category'] ?? 'peripheral') === 'peripheral'));
        usort($peripheralDays, static function (string $a, string $b) use ($daySegments): int {
            $scoreA = (float) ($daySegments[$a]['score'] ?? 0.0);
            $scoreB = (float) ($daySegments[$b]['score'] ?? 0.0);

            if ($scoreA === $scoreB) {
                return strcmp($a, $b);
            }

            return $scoreA <=> $scoreB;
        });

        foreach ($peripheralDays as $day) {
            if ($excess <= 0) {
                break;
            }

            $current = $dayQuotas[$day] ?? 0;
            $minimum = $minimumPerDay[$day] ?? 0;
            $room    = $current - $minimum;
            if ($room <= 0) {
                continue;
            }

            $reduction        = min($room, $excess);
            $dayQuotas[$day] -= $reduction;
            $excess          -= $reduction;
        }

        if ($excess <= 0) {
            return $dayQuotas;
        }

        $coreDays = array_keys(array_filter($daySegments, static fn (array $info): bool => ($info['category'] ?? 'peripheral') === 'core'));
        usort($coreDays, static function (string $a, string $b) use ($daySegments): int {
            $scoreA = (float) ($daySegments[$a]['score'] ?? 0.0);
            $scoreB = (float) ($daySegments[$b]['score'] ?? 0.0);

            if ($scoreA === $scoreB) {
                return strcmp($a, $b);
            }

            return $scoreA <=> $scoreB;
        });

        foreach ($coreDays as $day) {
            if ($excess <= 0) {
                break;
            }

            $current = $dayQuotas[$day] ?? 0;
            $minimum = $minimumPerDay[$day] ?? 0;
            $room    = $current - $minimum;
            if ($room <= 0) {
                continue;
            }

            $reduction        = min($room, $excess);
            $dayQuotas[$day] -= $reduction;
            $excess          -= $reduction;
        }

        return $dayQuotas;
    }

    /**
     * @return array<int, float|null>
     */
    private function extractQualityScores(ClusterDraft $draft): array
    {
        $params = $draft->getParams();
        $details = $params['member_quality']['members'] ?? null;
        $scores  = [];

        if (!is_array($details)) {
            return $scores;
        }

        foreach ($details as $memberId => $info) {
            if (!is_array($info)) {
                continue;
            }

            $score = $info['score'] ?? null;
            if ($score === null) {
                $scores[(int) $memberId] = null;
                continue;
            }

            if (!is_float($score) && !is_int($score)) {
                continue;
            }

            $scores[(int) $memberId] = (float) $score;
        }

        return $scores;
    }

    /**
     * @param array<string,mixed> $telemetry
     *
     * @return array<string, mixed>
     */
    private function augmentTelemetry(
        ClusterDraft $draft,
        SelectionPolicy $policy,
        array $telemetry,
        int $preCount,
        int $postCount,
    ): array {
        $telemetry['pre_count']  = $preCount;
        $telemetry['post_count'] = $postCount;
        if (!isset($telemetry['policy']) || !is_array($telemetry['policy'])) {
            $telemetry['policy'] = [];
        }

        $telemetry['policy']['profile']      = $policy->getProfileKey();
        $telemetry['policy']['storyline']    = $draft->getStoryline();
        $telemetry['policy']['target_total'] = $policy->getTargetTotal();
        $telemetry['policy']['minimum_total'] = $policy->getMinimumTotal();

        $maxPerDay = $policy->getMaxPerDay();
        if ($maxPerDay !== null) {
            $telemetry['policy']['max_per_day'] = $maxPerDay;
        }

        $metadata = $policy->getMetadata();
        if ($metadata !== []) {
            $telemetry['policy']['metadata'] = $metadata;

            if (array_key_exists('run_length_days', $metadata) && $metadata['run_length_days'] !== null) {
                $telemetry['policy']['run_length_days'] = $metadata['run_length_days'];
            }

            if (array_key_exists('constraint_overrides', $metadata) && is_array($metadata['constraint_overrides'])) {
                $telemetry['policy']['constraint_overrides'] = $metadata['constraint_overrides'];
            }
        }

        $telemetry['storyline'] = $draft->getStoryline();

        $metrics = $telemetry['metrics'] ?? [];
        $timeSamples = [];
        $hashSamples = [];

        if (is_array($metrics)) {
            $timeSamplesRaw = $metrics['time_gaps'] ?? [];
            if (is_array($timeSamplesRaw)) {
                $timeSamples = $timeSamplesRaw;
            }

            $hashSamplesRaw = $metrics['phash_distances'] ?? [];
            if (is_array($hashSamplesRaw)) {
                $hashSamples = $hashSamplesRaw;
            }
        }

        $telemetry['avg_time_gap_s']     = $this->calculateAverage($timeSamples);
        $telemetry['avg_phash_distance'] = $this->calculateAverage($hashSamples);

        $telemetry['per_day_distribution'] = $telemetry['distribution']['per_day'] ?? [];
        $telemetry['per_year_distribution'] = $telemetry['distribution']['per_year'] ?? [];
        $telemetry['per_bucket_distribution'] = $telemetry['distribution']['per_bucket'] ?? [];
        $telemetry['algorithm'] = $draft->getAlgorithm();
        $telemetry['storyline'] = $draft->getStoryline();

        if (isset($telemetry['rejections']) && is_array($telemetry['rejections'])) {
            $telemetry['exclusion_reasons'] = $telemetry['rejections'];
            $telemetry['rejection_counts']  = $telemetry['rejections'];
        } else {
            $telemetry['exclusion_reasons'] = [];
            $telemetry['rejection_counts']  = [];
        }

        $telemetry['counts'] = [
            'raw'     => $preCount,
            'curated' => $postCount,
            'dropped' => $preCount > $postCount ? $preCount - $postCount : 0,
        ];

        unset($telemetry['distribution'], $telemetry['metrics']);

        return $telemetry;
    }

    /**
     * @param array<int|float> $samples
     */
    private function calculateAverage(array $samples): float
    {
        $total = 0.0;
        $count = 0;

        foreach ($samples as $sample) {
            if (is_int($sample) || is_float($sample)) {
                $total += (float) $sample;
                ++$count;
            }
        }

        if ($count === 0) {
            return 0.0;
        }

        return $total / $count;
    }

    /**
     * @param array<string, int|float|string|null> $payload
     */
    private function emitMonitoring(string $event, array $payload): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('member_curation', $event, $payload);
    }
}
