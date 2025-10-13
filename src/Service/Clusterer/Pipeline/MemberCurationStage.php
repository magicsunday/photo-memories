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

            $policy      = $this->policyProvider->forAlgorithm($draft->getAlgorithm(), $draft->getStoryline());
            $daySegments = $this->extractDaySegments($draft);
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
            $telemetry = $this->augmentTelemetry($draft, $policy->getProfileKey(), $resultSet->getTelemetry(), $preCount, $postCount);

            $params = $draft->getParams();
            $params['member_selection'] = $telemetry;

            $result[] = $draft->withMembers($curated, $params);

            $rejections = [];
            if (isset($telemetry['rejections']) && is_array($telemetry['rejections'])) {
                $rejections = $telemetry['rejections'];
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

        $dayCount = count($daySegments);
        $baseCap  = $policy->getMaxPerDay();
        if ($baseCap === null) {
            $baseCap = max(1, (int) ceil($policy->getTargetTotal() / max(1, $dayCount)));
        }

        $targetTotal     = $policy->getTargetTotal();
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
        foreach ($daySegments as $day => $info) {
            $quota = $baseCap ?? $policy->getTargetTotal();
            if ($quota < 1) {
                $quota = 1;
            }

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
            } else {
                if ($quota < 1) {
                    $quota = 1;
                }
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

        $policyWithContext = $policy->withDayContext($dayQuotas, $daySegments, $peripheralQuotaLimit, $peripheralHardCap);

        $staypointCap = $policyWithContext->getMaxPerStaypoint();
        if ($staypointCap !== null) {
            $staypointBase = $policy->getMaxPerDay();
            if ($staypointBase === null && $dayQuotas !== []) {
                $staypointBase = max($dayQuotas);
            }

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
        string $profile,
        array $telemetry,
        int $preCount,
        int $postCount,
    ): array {
        $telemetry['pre_count']  = $preCount;
        $telemetry['post_count'] = $postCount;
        if (!isset($telemetry['policy']) || !is_array($telemetry['policy'])) {
            $telemetry['policy'] = [];
        }

        $telemetry['policy']['profile'] = $profile;
        $telemetry['policy']['storyline'] = $draft->getStoryline();
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
