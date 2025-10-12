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
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function count;
use function is_array;
use function is_float;
use function is_int;

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

            $policy = $this->policyProvider->forAlgorithm($draft->getAlgorithm(), $draft->getStoryline());
            $media  = $this->mediaLookup->findByIds($members);

            $mediaMap = [];
            foreach ($media as $entity) {
                $mediaMap[$entity->getId()] = $entity;
            }

            $qualityScores = $this->extractQualityScores($draft);
            $context       = new MemberSelectionContext($draft, $policy, $mediaMap, $qualityScores);

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
