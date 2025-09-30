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

use function array_keys;
use function array_unique;
use function array_values;

final readonly class ClusterPersistenceService implements ClusterPersistenceInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private int $defaultBatchSize = 250,
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
            $fp      = Cluster::computeFingerprint($d->getMembers());
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
            $alg = $d->getAlgorithm();
            $fp  = Cluster::computeFingerprint($d->getMembers());
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
                $d->getMembers()
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
}
