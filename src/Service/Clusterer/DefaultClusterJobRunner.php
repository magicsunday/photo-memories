<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterPersistenceInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\HybridClustererInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressReporterInterface;

use function array_keys;
use function count;
use function max;
use function microtime;
use function sprintf;

/**
 * Class DefaultClusterJobRunner
 */
final readonly class DefaultClusterJobRunner implements ClusterJobRunnerInterface
{
    public function __construct(
        private EntityManagerInterface       $entityManager,
        private HybridClustererInterface     $clusterer,
        private ClusterConsolidatorInterface $consolidator,
        private ClusterPersistenceInterface  $persistence,
    ) {
    }

    public function run(ClusterJobOptions $options, ProgressReporterInterface $progressReporter): ClusterJobResult
    {
        $countQb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Media::class, 'm');

        $listQb = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->orderBy('m.takenAt', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        $since = $options->getSince();
        if ($since instanceof DateTimeImmutable) {
            $countQb->andWhere('m.takenAt >= :since')->setParameter('since', $since);
            $listQb->andWhere('m.takenAt >= :since')->setParameter('since', $since);
        }

        $limit = $options->getLimit();
        if ($limit !== null && $limit > 0) {
            $listQb->setMaxResults($limit);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        if ($total === 0) {
            return new ClusterJobResult(0, 0, 0, 0, 0, 0, $options->isDryRun());
        }

        /** @var list<Media> $items */
        $items = [];
        $loadHandle = $progressReporter->create('Medien laden', '📥 Einlesen', $total);
        $loadStart  = microtime(true);
        foreach ($listQb->getQuery()->toIterable() as $row) {
            $items[] = $row;
            $processed = count($items);
            $loadHandle->setRate($this->formatRate($processed, $loadStart, 'Medien'));
            $loadHandle->advance();
        }
        $loadHandle->finish();

        $loadedCount = count($items);
        if ($loadedCount === 0) {
            return new ClusterJobResult($total, 0, 0, 0, 0, 0, $options->isDryRun());
        }

        $strategyCount = $this->clusterer->countStrategies();
        $clusterHandle = $progressReporter->create('Clustere', '🧩 Strategien', $strategyCount);
        $clusterStart  = microtime(true);

        /** @var list<ClusterDraft> $drafts */
        $drafts = $this->clusterer->build(
            $items,
            function (string $strategyName, int $index, int $strategyTotal) use ($clusterHandle): void {
                $clusterHandle->setPhase(sprintf('Strategie: %s (%d/%d)', $strategyName, $index, $strategyTotal));
                $clusterHandle->setRate('–');
            },
            function (string $strategyName, int $index, int $strategyTotal) use ($clusterHandle, $clusterStart, $loadedCount): void {
                $clusterHandle->setPhase(sprintf('Strategie: %s (%d/%d)', $strategyName, $index, $strategyTotal));
                $clusterHandle->setRate($this->formatRate($loadedCount, $clusterStart, 'Medien'));
                $clusterHandle->advance();
            },
        );
        $clusterHandle->finish();

        $draftCount = count($drafts);
        if ($draftCount === 0) {
            return new ClusterJobResult($total, $loadedCount, 0, 0, 0, 0, $options->isDryRun());
        }

        $consolidateHandle = $progressReporter->create('Konsolidieren', '🧹 Konsolidieren', $draftCount);
        $consolidateStart  = microtime(true);

        $drafts = $this->consolidator->consolidate(
            $drafts,
            function (int $done, int $maxSteps, string $stage) use ($consolidateHandle, $consolidateStart): void {
                $consolidateHandle->setPhase($stage);
                $consolidateHandle->setRate($this->formatRate($done, $consolidateStart, 'Schritte'));
                $consolidateHandle->setProgress($done);
            },
        );
        $consolidateHandle->finish();

        $consolidatedCount = count($drafts);
        if ($consolidatedCount === 0) {
            return new ClusterJobResult($total, $loadedCount, $draftCount, 0, 0, 0, $options->isDryRun());
        }

        $deleted = 0;
        if ($options->shouldReplace() && !$options->isDryRun()) {
            $deleted = $this->persistence->deleteByAlgorithms($this->collectAlgorithms($drafts));
        }

        $persistHandle = $progressReporter->create(
            $options->isDryRun() ? 'Persistieren (Trockenlauf)' : 'Persistieren',
            '💾 Speichern',
            $consolidatedCount,
        );
        $persistStart = microtime(true);

        $persisted = 0;
        if ($options->isDryRun()) {
            foreach ($drafts as $_) {
                ++$persisted;
                $persistHandle->setRate($this->formatRate($persisted, $persistStart, 'Cluster'));
                $persistHandle->advance();
            }
        } else {
            $persisted = $this->persistence->persistBatched(
                $drafts,
                10,
                function (int $persistedInBatch) use (&$persisted, $persistHandle, $persistStart): void {
                    $persisted += $persistedInBatch;
                    $persistHandle->setRate($this->formatRate($persisted, $persistStart, 'Cluster'));
                    $persistHandle->advance($persistedInBatch);
                },
            );
        }
        $persistHandle->finish();

        return new ClusterJobResult(
            $total,
            $loadedCount,
            $draftCount,
            $consolidatedCount,
            $persisted,
            $deleted,
            $options->isDryRun(),
        );
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<string>
     */
    private function collectAlgorithms(array $drafts): array
    {
        $algorithms = [];

        foreach ($drafts as $draft) {
            $algorithms[$draft->getAlgorithm()] = true;
        }

        return array_keys($algorithms);
    }

    private function formatRate(int $processed, float $startedAt, string $unit): string
    {
        $elapsed = max(0.000001, microtime(true) - $startedAt);
        $rate    = $processed / $elapsed;

        return sprintf('Durchsatz: %.1f %s/s', $rate, $unit);
    }
}
