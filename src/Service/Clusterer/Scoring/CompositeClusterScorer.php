<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterBuildProgressCallbackInterface;
use MagicSunday\Memories\Service\Feed\FeedUserPreferences;

use function array_keys;
use function array_map;
use function array_slice;
use function ceil;
use function count;
use function explode;
use function max;
use function min;
use function sort;
use function is_array;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_contains;
use function trim;
use function usort;

/**
 * Scores clustered media sets by combining multiple heuristic signals.
 */
final class CompositeClusterScorer
{
    private const DEFAULT_STORYLINE = 'default';

    /** @var list<ClusterScoreHeuristicInterface> */
    private array $heuristics;

    /** @var array<string,string> */
    private array $algorithmGroups = [];

    /** @var array<string,array<string,array<string,float>>> */
    private array $algorithmWeightOverrides = [];

    /**
     * @param iterable<ClusterScoreHeuristicInterface> $heuristics
     * @param array<string,float>                      $weights
     * @param array<string,float>                      $algorithmBoosts
     * @param array<string,array<string,float|int>>    $algorithmWeightOverrides
     */
    public function __construct(
        private EntityManagerInterface $em,
        iterable $heuristics,
        private array $weights = [
            'quality'       => 0.22,
            'aesthetics'    => 0.08,
            'people'        => 0.16,
            'content'       => 0.09,
            'density'       => 0.10,
            'novelty'       => 0.09,
            'holiday'       => 0.07,
            'recency'       => 0.12,
            'location'      => 0.05,
            'poi'           => 0.02,
            'time_coverage' => 0.10,
            'liveliness'    => 0.08,
        ],
        private array $algorithmBoosts = [],
        array $algorithmGroups = [],
        private string $defaultAlgorithmGroup = 'default',
        array $algorithmWeightOverrides = [],
    ) {
        $this->heuristics = [];
        foreach ($heuristics as $heuristic) {
            $this->heuristics[] = $heuristic;
        }

        foreach ($this->algorithmBoosts as $algorithm => $boost) {
            if ($boost <= 0.0) {
                throw new InvalidArgumentException(
                    sprintf('Algorithm boost must be > 0.0, got %s => %f',
                        $algorithm, $boost)
                );
            }
        }

        foreach ($algorithmGroups as $algorithm => $group) {
            if (!is_string($group) || $group === '') {
                continue;
            }

            $this->algorithmGroups[$algorithm] = $group;
        }

        $this->algorithmWeightOverrides = [];
        $this->algorithmWeightOverrides = $this->sanitizeAlgorithmWeightOverrides($algorithmWeightOverrides);
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return list<ClusterDraft>
     */
    public function score(
        array $clusters,
        ?ClusterBuildProgressCallbackInterface $progressCallback = null,
        ?FeedUserPreferences $preferences = null,
    ): array
    {
        if ($clusters === []) {
            return [];
        }

        $mediaMap = $this->loadMediaMap($clusters, $progressCallback);
        foreach ($this->heuristics as $heuristic) {
            if ($heuristic instanceof PreferenceAwareClusterScoreHeuristicInterface) {
                $heuristic->setFeedUserPreferences($preferences);
            }
            $heuristic->prepare($clusters, $mediaMap);
        }

        $clusterCount = count($clusters);
        if ($progressCallback !== null) {
            $progressCallback->onStageStart(ClusterBuildProgressCallbackInterface::STAGE_SCORING, $clusterCount);
        }

        $rawScores = [];
        foreach ($clusters as $index => $cluster) {
            $weightedValues = [];
            foreach ($this->heuristics as $heuristic) {
                if (!$heuristic->supports($cluster)) {
                    continue;
                }

                $heuristic->enrich($cluster, $mediaMap);
                $weightedValues[$heuristic->weightKey()] = $heuristic->score($cluster);
            }

            $score = $this->computeWeightedScore($cluster, $weightedValues);

            $rawScores[] = [
                'cluster'   => $cluster,
                'algorithm' => $cluster->getAlgorithm(),
                'score'     => $score,
            ];

            if ($progressCallback !== null) {
                $progressCallback->onStageProgress(
                    ClusterBuildProgressCallbackInterface::STAGE_SCORING,
                    $index + 1,
                    $clusterCount,
                    $cluster->getAlgorithm(),
                );
            }
        }

        if ($progressCallback !== null) {
            $progressCallback->onStageFinish(ClusterBuildProgressCallbackInterface::STAGE_SCORING, $clusterCount);
        }

        $distributions = $this->buildDistributions($rawScores);

        foreach ($rawScores as $entry) {
            $cluster   = $entry['cluster'];
            $algorithm = $entry['algorithm'];
            $rawScore  = $entry['score'];

            $cluster->setParam('group', $this->resolveGroup($algorithm));
            $cluster->setParam('pre_norm_score', $rawScore);

            $distribution = $distributions[$algorithm] ?? null;
            $normalised   = $distribution === null
                ? 0.5
                : $this->normaliseScore($rawScore, $distribution);

            $cluster->setParam('post_norm_score', $normalised);

            $boost = $this->algorithmBoosts[$algorithm] ?? 1.0;
            if ($boost !== 1.0) {
                $cluster->setParam('score_algorithm_boost', $boost);
            }

            $boosted = $normalised * $boost;
            $cluster->setParam('boosted_score', $boosted);
            $cluster->setParam('score', $boosted);
        }

        usort($clusters, static fn (ClusterDraft $a, ClusterDraft $b): int => ($b->getParams()['score'] ?? 0.0) <=> ($a->getParams()['score'] ?? 0.0));

        if ($preferences !== null) {
            foreach ($this->heuristics as $heuristic) {
                if ($heuristic instanceof PreferenceAwareClusterScoreHeuristicInterface) {
                    $heuristic->setFeedUserPreferences(null);
                }
            }
        }

        return $clusters;
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return array<int, Media>
     */
    private function loadMediaMap(array $clusters, ?ClusterBuildProgressCallbackInterface $progressCallback = null): array
    {
        $ids = [];
        foreach ($clusters as $c) {
            foreach ($c->getMembers() as $id) {
                $ids[$id] = true;
            }
        }

        $allIds = array_map(static fn (int $k): int => $k, array_keys($ids));
        if ($allIds === []) {
            return [];
        }

        $map   = [];
        $chunk = 1000;
        $totalIds = count($allIds);

        if ($progressCallback !== null) {
            $progressCallback->onStageStart(ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, $totalIds);
        }

        for ($i = 0, $n = $totalIds; $i < $n; $i += $chunk) {
            $slice = array_slice($allIds, $i, $chunk);
            $qb    = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Media::class, 'm')
                ->where('m.id IN (:ids)')
                ->setParameter('ids', $slice);
            /** @var list<Media> $rows */
            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $m) {
                $map[$m->getId()] = $m;
            }

            if ($progressCallback !== null) {
                $processed = min($totalIds, $i + count($slice));
                $progressCallback->onStageProgress(
                    ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA,
                    $processed,
                    $totalIds,
                    sprintf('Medien %d/%d geladen', $processed, $totalIds),
                );
            }
        }

        if ($progressCallback !== null) {
            $progressCallback->onStageFinish(ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, $totalIds);
        }

        return $map;
    }

    /**
     * @param array<string,float> $weightedValues
     */
    private function computeWeightedScore(ClusterDraft $cluster, array $weightedValues): float
    {
        $params  = $cluster->getParams();
        $weights = $this->resolveWeights($cluster);

        $quality    = $weightedValues['quality'] ?? 0.0;
        $aesthetics = $this->floatOrNull($params['aesthetics_score'] ?? null) ?? $quality;

        return ($weights['quality'] ?? 0.0) * $quality +
            ($weights['aesthetics'] ?? 0.0) * $aesthetics +
            ($weights['people'] ?? 0.0) * ($weightedValues['people'] ?? 0.0) +
            ($weights['content'] ?? 0.0) * ($weightedValues['content'] ?? 0.0) +
            ($weights['density'] ?? 0.0) * ($weightedValues['density'] ?? 0.0) +
            ($weights['novelty'] ?? 0.0) * ($weightedValues['novelty'] ?? 0.0) +
            ($weights['holiday'] ?? 0.0) * ($weightedValues['holiday'] ?? 0.0) +
            ($weights['recency'] ?? 0.0) * ($weightedValues['recency'] ?? 0.0) +
            ($weights['location'] ?? 0.0) * ($weightedValues['location'] ?? 0.0) +
            ($weights['poi'] ?? 0.0) * ($weightedValues['poi'] ?? 0.0) +
            ($weights['time_coverage'] ?? 0.0) * ($weightedValues['time_coverage'] ?? 0.0) +
            ($weights['liveliness'] ?? 0.0) * ($weightedValues['liveliness'] ?? 0.0);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string,float>
     */
    private function resolveWeights(ClusterDraft $cluster): array
    {
        $weights = $this->weights;

        $algorithm = $cluster->getAlgorithm();
        if (isset($this->algorithmWeightOverrides[$algorithm])) {
            $overrides = $this->resolveStorylineOverrides($cluster->getStoryline(), $this->algorithmWeightOverrides[$algorithm]);
            if ($overrides !== []) {
                $weights = $this->mergeWeights($weights, $overrides);
            }
        }

        $clusterOverrides = $cluster->getParams()['score_weight_overrides'] ?? null;
        if ($clusterOverrides !== null) {
            $weights = $this->mergeWeights($weights, $clusterOverrides);
        }

        return $weights;
    }

    /**
     * @param array<string,float> $weights
     * @param mixed               $overrides
     *
     * @return array<string,float>
     */
    private function mergeWeights(array $weights, mixed $overrides): array
    {
        if (!is_array($overrides)) {
            return $weights;
        }

        foreach ($overrides as $key => $value) {
            if (!is_string($key) || !is_numeric($value)) {
                continue;
            }

            $weight = (float) $value;
            if ($weight < 0.0) {
                continue;
            }

            $weights[$key] = $weight;
        }

        return $weights;
    }

    /**
     * @param mixed $overrides
     *
     * @return array<string,float>
     */
    private function sanitizeWeightOverrides(mixed $overrides): array
    {
        if (!is_array($overrides)) {
            return [];
        }

        $result = [];
        foreach ($overrides as $key => $value) {
            if (!is_string($key) || !is_numeric($value)) {
                continue;
            }

            $weight = (float) $value;
            if ($weight < 0.0) {
                continue;
            }

            $result[$key] = $weight;
        }

        return $result;
    }

    /**
     * @param array<string,array<string,float|int>> $overrides
     *
     * @return array<string,array<string,float>>
     */
    private function sanitizeAlgorithmWeightOverrides(array $overrides): array
    {
        $result = [];

        foreach ($overrides as $algorithm => $mapping) {
            if (!is_string($algorithm) || $algorithm === '') {
                continue;
            }

            if (!is_array($mapping)) {
                continue;
            }

            if ($this->isFlatWeightMap($mapping)) {
                $sanitised = $this->sanitizeWeightOverrides($mapping);
                if ($sanitised === []) {
                    continue;
                }

                $result[$algorithm] = [self::DEFAULT_STORYLINE => $sanitised];

                continue;
            }

            $storylines = [];
            foreach ($mapping as $storyline => $storylineOverrides) {
                if (!is_string($storyline) || $storyline === '') {
                    continue;
                }

                $sanitised = $this->sanitizeWeightOverrides($storylineOverrides);
                if ($sanitised === []) {
                    continue;
                }

                $storylines[$storyline] = $sanitised;
            }

            if ($storylines === []) {
                continue;
            }

            if (!isset($storylines[self::DEFAULT_STORYLINE])) {
                $first = reset($storylines);
                if (is_array($first) && $first !== []) {
                    $storylines[self::DEFAULT_STORYLINE] = $first;
                }
            }

            $result[$algorithm] = $storylines;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $mapping
     */
    private function isFlatWeightMap(array $mapping): bool
    {
        foreach ($mapping as $value) {
            if (!is_numeric($value)) {
                return false;
            }
        }

        return $mapping !== [];
    }

    /**
     * @param array<string,array<string,float>> $overrides
     *
     * @return array<string,float>
     */
    private function resolveStorylineOverrides(string $storyline, array $overrides): array
    {
        $candidates = $this->storylineCandidates($storyline);
        $candidates[] = self::DEFAULT_STORYLINE;

        foreach ($candidates as $candidate) {
            if (isset($overrides[$candidate])) {
                return $overrides[$candidate];
            }
        }

        $fallback = reset($overrides);

        return is_array($fallback) ? $fallback : [];
    }

    /**
     * @return list<string>
     */
    private function storylineCandidates(string $storyline): array
    {
        $trimmed = trim($storyline);
        if ($trimmed === '' || $trimmed === self::DEFAULT_STORYLINE) {
            return [];
        }

        $candidates = [$trimmed];
        if (str_contains($trimmed, '.')) {
            $parts = explode('.', $trimmed);
            $suffix = end($parts);
            if (is_string($suffix) && $suffix !== '' && $suffix !== $trimmed) {
                $candidates[] = $suffix;
            }
        }

        return $candidates;
    }

    private function resolveGroup(string $algorithm): string
    {
        $group = $this->algorithmGroups[$algorithm] ?? null;

        if (is_string($group) && $group !== '') {
            return $group;
        }

        return $this->defaultAlgorithmGroup;
    }

    /**
     * @param list<array{cluster: ClusterDraft, algorithm: string, score: float}> $rawScores
     *
     * @return array<string,array{min: float, q1: float, median: float, q3: float, max: float}>
     */
    private function buildDistributions(array $rawScores): array
    {
        if ($rawScores === []) {
            return [];
        }

        $grouped = [];
        foreach ($rawScores as $entry) {
            $grouped[$entry['algorithm']][] = $entry['score'];
        }

        $distributions = [];
        foreach ($grouped as $algorithm => $scores) {
            sort($scores);

            $distributions[$algorithm] = [
                'min'    => $scores[0],
                'q1'     => $this->quantile($scores, 0.25),
                'median' => $this->quantile($scores, 0.5),
                'q3'     => $this->quantile($scores, 0.75),
                'max'    => $scores[count($scores) - 1],
            ];
        }

        return $distributions;
    }

    /**
     * @param list<float> $sorted
     */
    private function quantile(array $sorted, float $q): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }

        $position   = ($count - 1) * $q;
        $lowerIndex = (int) $position;
        $upperIndex = (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return $sorted[$lowerIndex];
        }

        $lowerWeight = $upperIndex - $position;
        $upperWeight = 1.0 - $lowerWeight;

        return ($sorted[$lowerIndex] * $lowerWeight) + ($sorted[$upperIndex] * $upperWeight);
    }

    /**
     * @param array{min: float, q1: float, median: float, q3: float, max: float} $distribution
     */
    private function normaliseScore(float $rawScore, array $distribution): float
    {
        $minValue = $distribution['min'];
        $maxValue = $distribution['max'];

        if ($maxValue - $minValue <= 1e-9) {
            return 0.5;
        }

        $clamped = max($minValue, min($rawScore, $maxValue));

        $q1  = $distribution['q1'];
        $q3  = $distribution['q3'];
        $iqr = $q3 - $q1;

        if ($iqr <= 1e-9) {
            $percent = ($clamped - $minValue) / ($maxValue - $minValue);

            return 0.1 + 0.8 * $this->clamp01($percent);
        }

        if ($clamped <= $q1) {
            $lowerSpan = max($q1 - $minValue, 1e-9);
            $percent   = (($clamped - $minValue) / $lowerSpan) * 0.25;
        } elseif ($clamped < $q3) {
            $percent = 0.25 + (($clamped - $q1) / $iqr) * 0.5;
        } else {
            $upperSpan = max($maxValue - $q3, 1e-9);
            $percent   = 0.75 + (($clamped - $q3) / $upperSpan) * 0.25;
        }

        return 0.1 + 0.8 * $this->clamp01($percent);
    }

    private function clamp01(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
