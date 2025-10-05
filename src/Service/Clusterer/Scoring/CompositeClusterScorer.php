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

use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function is_numeric;
use function is_string;
use function sprintf;
use function usort;

/**
 * Scores clustered media sets by combining multiple heuristic signals.
 */
final class CompositeClusterScorer
{
    /** @var list<ClusterScoreHeuristicInterface> */
    private array $heuristics;

    /** @var array<string,string> */
    private array $algorithmGroups = [];

    /**
     * @param iterable<ClusterScoreHeuristicInterface> $heuristics
     * @param array<string,float>                      $weights
     * @param array<string,float>                      $algorithmBoosts
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
        ],
        private array $algorithmBoosts = [],
        array $algorithmGroups = [],
        private string $defaultAlgorithmGroup = 'default',
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
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return list<ClusterDraft>
     */
    public function score(array $clusters): array
    {
        if ($clusters === []) {
            return [];
        }

        $mediaMap = $this->loadMediaMap($clusters);
        foreach ($this->heuristics as $heuristic) {
            $heuristic->prepare($clusters, $mediaMap);
        }

        foreach ($clusters as $cluster) {
            $weightedValues = [];
            foreach ($this->heuristics as $heuristic) {
                if (!$heuristic->supports($cluster)) {
                    continue;
                }

                $heuristic->enrich($cluster, $mediaMap);
                $weightedValues[$heuristic->weightKey()] = $heuristic->score($cluster);
            }

            $score = $this->computeWeightedScore($cluster, $weightedValues);

            $algorithm = $cluster->getAlgorithm();
            $cluster->setParam('group', $this->resolveGroup($algorithm));
            $boost = $this->algorithmBoosts[$algorithm] ?? 1.0;
            if ($boost !== 1.0) {
                $score *= $boost;
                $cluster->setParam('score_algorithm_boost', $boost);
            }

            $cluster->setParam('score', $score);
        }

        usort($clusters, static fn (ClusterDraft $a, ClusterDraft $b): int => ($b->getParams()['score'] ?? 0.0) <=> ($a->getParams()['score'] ?? 0.0));

        return $clusters;
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return array<int, Media>
     */
    private function loadMediaMap(array $clusters): array
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
        for ($i = 0, $n = count($allIds); $i < $n; $i += $chunk) {
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
        }

        return $map;
    }

    /**
     * @param array<string,float> $weightedValues
     */
    private function computeWeightedScore(ClusterDraft $cluster, array $weightedValues): float
    {
        $params = $cluster->getParams();

        $quality    = $weightedValues['quality'] ?? 0.0;
        $aesthetics = $this->floatOrNull($params['aesthetics_score'] ?? null) ?? $quality;

        return ($this->weights['quality'] ?? 0.0) * $quality +
            ($this->weights['aesthetics'] ?? 0.0) * $aesthetics +
            ($this->weights['people'] ?? 0.0) * ($weightedValues['people'] ?? 0.0) +
            ($this->weights['content'] ?? 0.0) * ($weightedValues['content'] ?? 0.0) +
            ($this->weights['density'] ?? 0.0) * ($weightedValues['density'] ?? 0.0) +
            ($this->weights['novelty'] ?? 0.0) * ($weightedValues['novelty'] ?? 0.0) +
            ($this->weights['holiday'] ?? 0.0) * ($weightedValues['holiday'] ?? 0.0) +
            ($this->weights['recency'] ?? 0.0) * ($weightedValues['recency'] ?? 0.0) +
            ($this->weights['location'] ?? 0.0) * ($weightedValues['location'] ?? 0.0) +
            ($this->weights['poi'] ?? 0.0) * ($weightedValues['poi'] ?? 0.0) +
            ($this->weights['time_coverage'] ?? 0.0) * ($weightedValues['time_coverage'] ?? 0.0);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function resolveGroup(string $algorithm): string
    {
        $group = $this->algorithmGroups[$algorithm] ?? null;

        if (is_string($group) && $group !== '') {
            return $group;
        }

        return $this->defaultAlgorithmGroup;
    }
}
