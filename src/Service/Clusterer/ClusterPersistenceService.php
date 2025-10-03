<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterPersistenceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_keys;
use function array_key_exists;
use function array_unique;
use function array_values;
use function array_slice;
use function count;
use function is_array;
use function is_int;
use function is_numeric;

final readonly class ClusterPersistenceService implements ClusterPersistenceInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private int $defaultBatchSize = 250,
        #[Autowire('%memories.cluster.persistence.max_members%')]
        private int $maxMembers = 20,
    ) {
    }

    /**
     * Persist drafts in batches while skipping already existing (algorithm,fingerprint) pairs.
     *
     * @param list<ClusterDraft>                   $drafts
     * @param int                                  $batchSize
     * @param callable(int $persistedInBatch)|null $onBatchPersisted
     *
     * @return int Number of newly persisted clusters
     */
    public function persistBatched(array $drafts, int $batchSize, ?callable $onBatchPersisted): int
    {
        if ($drafts === []) {
            return 0;
        }

        $batchSize = $batchSize > 0 ? $batchSize : $this->defaultBatchSize;

        // 1) Build pair list (alg, fp) for all drafts
        /** @var list<array{alg:string, fp:string}> $pairs */
        $pairs = [];
        foreach ($drafts as $d) {
            $alg     = $d->getAlgorithm();
            $ordered = $this->resolveOrderedMembers($d);
            $members = $this->clampMembers($ordered);
            $fp      = Cluster::computeFingerprint($members);
            $pairs[] = ['alg' => $alg, 'fp' => $fp];
        }

        // 2) Load existing pairs from DB into a set: "alg|fp" => true
        $existing = $this->loadExistingPairs($pairs);

        // Also prevent duplicates within this run:
        /** @var array<string, bool> $seenThisRun */
        $seenThisRun = [];

        $persisted = 0;
        $inBatch   = 0;

        // 3) Persist only new pairs
        foreach ($drafts as $d) {
            $alg     = $d->getAlgorithm();
            $ordered = $this->resolveOrderedMembers($d);
            $members = $this->clampMembers($ordered);
            $fp      = Cluster::computeFingerprint($members);
            $key = $alg . '|' . $fp;
            if (isset($existing[$key])) {
                // already persisted earlier or within this same run
                continue;
            }

            if (isset($seenThisRun[$key])) {
                // already persisted earlier or within this same run
                continue;
            }

            // Construct and fill entity
            $entity = new Cluster(
                $alg,
                $d->getParams(),
                $d->getCentroid(),
                $members
            );

            $this->em->persist($entity);

            ++$persisted;
            ++$inBatch;
            $seenThisRun[$key] = true;

            if ($inBatch >= $batchSize) {
                $this->em->flush();
                $this->em->clear();

                if ($onBatchPersisted !== null) {
                    $onBatchPersisted($inBatch);
                }

                $inBatch = 0;
            }
        }

        if ($inBatch > 0) {
            $this->em->flush();
            $this->em->clear();

            if ($onBatchPersisted !== null) {
                $onBatchPersisted($inBatch);
            }
        }

        return $persisted;
    }

    /**
     * Load already persisted (algorithm,fingerprint) pairs for the given candidate set.
     *
     * @param list<array{alg:string, fp:string}> $pairs
     *
     * @return array<string,bool> map "alg|fp" => true
     */
    private function loadExistingPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        // Deduplicate parameters to keep the IN-clauses small
        $algs = [];
        $fps  = [];
        foreach ($pairs as $p) {
            $algs[$p['alg']] = true;
            $fps[$p['fp']]   = true;
        }

        /** @var list<string> $algList */
        $algList = array_keys($algs);
        /** @var list<string> $fpList */
        $fpList = array_keys($fps);

        $qb = $this->em->createQueryBuilder()
            ->select('c.algorithm AS alg', 'c.fingerprint AS fp')
            ->from(Cluster::class, 'c')
            ->where('c.algorithm IN (:algs)')
            ->andWhere('c.fingerprint IN (:fps)')
            ->setParameter('algs', $algList)
            ->setParameter('fps', $fpList);

        $q = $qb->getQuery();

        /** @var list<array{alg:string, fp:string}> $rows */
        $rows = $q->getResult();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['alg'] . '|' . $r['fp']] = true;
        }

        return $out;
    }

    /**
     * Remove all persisted clusters for the provided algorithm list.
     *
     * @param list<string> $algorithms
     */
    public function deleteByAlgorithms(array $algorithms): int
    {
        if ($algorithms === []) {
            return 0;
        }

        $uniqueAlgorithms = array_values(array_unique($algorithms));

        $q = $this->em->createQueryBuilder()
            ->delete(Cluster::class, 'c')
            ->where('c.algorithm IN (:algs)')
            ->setParameter('algs', $uniqueAlgorithms)
            ->getQuery();

        $deleted = (int) $q->execute();

        $this->em->clear();

        return $deleted;
    }

    /**
     * @return list<int>
     */
    private function resolveOrderedMembers(ClusterDraft $draft): array
    {
        $original = $draft->getMembers();
        $params   = $draft->getParams();
        $metadata = $params['member_quality'] ?? null;
        if (!is_array($metadata)) {
            return $original;
        }

        $balanced = $this->normaliseOrderList($metadata['ordered'] ?? null, $original);

        if ($draft->getAlgorithm() === 'vacation') {
            if ($balanced !== null) {
                return $balanced;
            }

            $quality = $this->resolveQualityRankedOrder($metadata, $original);
            if ($quality !== null) {
                return $quality;
            }

            return $original;
        }

        $quality = $this->resolveQualityRankedOrder($metadata, $original);
        if ($quality !== null) {
            return $quality;
        }

        if ($balanced !== null) {
            return $balanced;
        }

        return $original;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<int>            $original
     *
     * @return list<int>|null
     */
    private function resolveQualityRankedOrder(array $metadata, array $original): ?array
    {
        $qualityRanked = $metadata['quality_ranked'] ?? null;
        $quality = $this->extractOrderedList($qualityRanked, $original);
        if ($quality !== null) {
            return $quality;
        }

        $legacyRanked = $metadata['ranked'] ?? null;

        return $this->extractOrderedList($legacyRanked, $original);
    }

    /**
     * @param mixed     $value
     * @param list<int> $original
     *
     * @return list<int>|null
     */
    private function extractOrderedList($value, array $original): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        if (isset($value['ordered'])) {
            return $this->normaliseOrderList($value['ordered'], $original);
        }

        $ids = [];
        foreach ($value as $entry) {
            if (is_int($entry)) {
                $ids[] = $entry;
                continue;
            }

            if (is_numeric($entry)) {
                $ids[] = (int) $entry;
                continue;
            }

            if (is_array($entry) && array_key_exists('id', $entry)) {
                $idValue = $entry['id'];
                if (is_int($idValue)) {
                    $ids[] = $idValue;
                    continue;
                }

                if (is_numeric($idValue)) {
                    $ids[] = (int) $idValue;
                }
            }
        }

        return $this->normaliseOrderList($ids, $original);
    }

    /**
     * @param mixed     $raw
     * @param list<int> $original
     *
     * @return list<int>|null
     */
    private function normaliseOrderList($raw, array $original): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        /** @var array<int,int> $originalCounts */
        $originalCounts = [];
        foreach ($original as $id) {
            $intId = (int) $id;
            $originalCounts[$intId] = ($originalCounts[$intId] ?? 0) + 1;
        }

        if ($originalCounts === []) {
            return null;
        }

        /** @var list<int> $ordered */
        $ordered = [];
        /** @var array<int,int> $orderedCounts */
        $orderedCounts = [];

        foreach ($raw as $value) {
            $intValue = null;
            if (is_int($value)) {
                $intValue = $value;
            } elseif (is_numeric($value)) {
                $intValue = (int) $value;
            }

            if ($intValue === null) {
                continue;
            }

            if (!isset($originalCounts[$intValue])) {
                continue;
            }

            $ordered[] = $intValue;
            $orderedCounts[$intValue] = ($orderedCounts[$intValue] ?? 0) + 1;
        }

        if ($ordered === []) {
            return null;
        }

        foreach ($original as $id) {
            $intId   = (int) $id;
            $expected = $originalCounts[$intId] ?? 0;
            $current  = $orderedCounts[$intId] ?? 0;
            if ($current >= $expected) {
                continue;
            }

            $ordered[]             = $intId;
            $orderedCounts[$intId] = $current + 1;
        }

        return $ordered;
    }

    /**
     * @param list<int> $members
     *
     * @return list<int>
     */
    private function clampMembers(array $members): array
    {
        if ($this->maxMembers <= 0) {
            return $members;
        }

        if (count($members) <= $this->maxMembers) {
            return $members;
        }

        return array_slice($members, 0, $this->maxMembers);
    }
}
