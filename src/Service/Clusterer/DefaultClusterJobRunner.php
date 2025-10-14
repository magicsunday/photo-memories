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
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterBuildProgressCallbackInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterPersistenceInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\HybridClustererInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressHandleInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressReporterInterface;

use function count;
use function max;
use function min;
use function microtime;
use function sprintf;

/**
 * Class DefaultClusterJobRunner.
 */
final readonly class DefaultClusterJobRunner implements ClusterJobRunnerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HybridClustererInterface $clusterer,
        private ClusterConsolidatorInterface $consolidator,
        private ClusterPersistenceInterface $persistence,
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
        $items      = [];
        $loadHandle = $progressReporter->create('Medien laden', '📥 Einlesen', $total);
        $loadStart  = microtime(true);
        foreach ($listQb->getQuery()->toIterable() as $row) {
            $items[]   = $row;
            $processed = count($items);
            $loadHandle->setRate($this->formatRate($processed, $loadStart, 'Medien'));
            $loadHandle->advance();
        }
        $loadHandle->finish();

        $loadedCount = count($items);
        if ($loadedCount === 0) {
            return new ClusterJobResult($total, 0, 0, 0, 0, 0, $options->isDryRun());
        }

        $outdatedMedia = [];
        foreach ($items as $media) {
            if ($media->getFeatureVersion() !== MetadataFeatureVersion::CURRENT) {
                $outdatedMedia[] = $media;
            }
        }

        if ($outdatedMedia !== []) {
            $outdatedCount = count($outdatedMedia);
            $warningHandle = $progressReporter->create('Warnung', '⚠️ Feature-Version prüfen', $outdatedCount);
            $warningHandle->setPhase(sprintf(
                'Erwartete Feature-Version %d, %d Medien benötigen eine erneute Metadaten-Indexierung.',
                MetadataFeatureVersion::CURRENT,
                $outdatedCount,
            ));
            $warningHandle->setRate('Bitte führe memories:index vor dem Clustering erneut aus.');
            $warningHandle->advance($outdatedCount);
            $warningHandle->finish();

            if ($options->shouldReplace()) {
                return new ClusterJobResult($total, $loadedCount, 0, 0, 0, 0, $options->isDryRun());
            }
        }

        $deleted = 0;
        if ($options->shouldReplace() && !$options->isDryRun()) {
            $deleted = $this->persistence->deleteAll();
        }

        $strategyCount = $this->clusterer->countStrategies();
        $clusterHandle = $progressReporter->create('Clustere', '🧩 Strategien', $strategyCount);
        $clusterHandle->setMax(max(1, $strategyCount));
        $clusterHandle->setDetail('Vorbereitung');
        $clusterStart  = microtime(true);

        $postProcessingProgress = new ProgressReporterClusterBuildListener($progressReporter);

        /** @var list<ClusterDraft> $drafts */
        $drafts = $this->clusterer->build(
            $items,
            function (string $strategyName, int $index, int $strategyTotal) use ($clusterHandle): void {
                $clusterHandle->setPhase(sprintf('Strategie: %s (%d/%d)', $strategyName, $index, $strategyTotal));
                $clusterHandle->setDetail('Vorbereitung');
                $clusterHandle->setRate('–');
                $clusterHandle->setProgress(max(0, $index - 1));
            },
            function (string $strategyName, int $index, int $strategyTotal) use ($clusterHandle, $clusterStart, $loadedCount): void {
                $clusterHandle->setPhase(sprintf('Strategie: %s (%d/%d)', $strategyName, $index, $strategyTotal));
                $clusterHandle->setDetail('Abgeschlossen');
                $clusterHandle->setRate($this->formatRate($loadedCount, $clusterStart, 'Medien'));
                $clusterHandle->advance();
            },
            function (string $strategyName, int $index, int $strategyTotal) use ($clusterHandle, $loadedCount): ProgressHandleInterface {
                $clusterHandle->setPhase(sprintf('Strategie: %s (%d/%d)', $strategyName, $index, $strategyTotal));
                $clusterHandle->setDetail('Vorbereitung');
                $clusterHandle->setRate('–');
                $clusterHandle->setProgress(max(0, $index - 1));

                $strategyHandle = $clusterHandle->createChildHandle(
                    sprintf('Strategie: %s', $strategyName),
                    sprintf('⚙️ %s', $strategyName),
                    max(1, $loadedCount),
                );
                $strategyHandle->setMax(max(1, $loadedCount));
                $strategyHandle->setPhase('Vorbereitung');
                $strategyHandle->setDetail('Warten auf Fortschritt');
                $strategyHandle->setRate('–');
                $strategyHandle->setProgress(0);

                return $strategyHandle;
            },
            progressCallback: $postProcessingProgress,
        );
        $clusterHandle->finish();

        $postProcessingProgress->finalize();

        $draftCount = count($drafts);
        if ($draftCount === 0) {
            return new ClusterJobResult($total, $loadedCount, 0, 0, 0, $deleted, $options->isDryRun());
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
            return new ClusterJobResult($total, $loadedCount, $draftCount, 0, 0, $deleted, $options->isDryRun());
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
            $persisted = $this->persistence->persistStreaming(
                $drafts,
                function (int $persistedNow) use (&$persisted, $persistHandle, $persistStart): void {
                    $persisted += $persistedNow;
                    $persistHandle->setRate($this->formatRate($persisted, $persistStart, 'Cluster'));
                    $persistHandle->advance($persistedNow);
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

    private function formatRate(int $processed, float $startedAt, string $unit): string
    {
        $elapsed = max(0.000001, microtime(true) - $startedAt);
        $rate    = $processed / $elapsed;

        return sprintf('Durchsatz: %.1f %s/s', $rate, $unit);
    }
}

final class ProgressReporterClusterBuildListener implements ClusterBuildProgressCallbackInterface
{
    private ?ProgressHandleInterface $handle = null;

    private ?float $stageStartedAt = null;

    private int $maxTotal = 1;

    private string $currentStage = '';

    private bool $finished = false;

    public function __construct(private readonly ProgressReporterInterface $progressReporter)
    {
    }

    public function onStageStart(string $stage, int $total): void
    {
        $this->currentStage   = $stage;
        $this->stageStartedAt = microtime(true);
        $this->maxTotal       = max($this->maxTotal, max(1, $total));

        if ($this->handle === null) {
            $this->handle = $this->progressReporter->create('Bewerten', '🏅 Score & Titel', $this->maxTotal);
            $this->handle->setProgress(0);
            $this->handle->setRate('–');
        } else {
            $this->handle->setMax($this->maxTotal);
        }

        if ($this->handle === null) {
            return;
        }

        switch ($stage) {
            case ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA:
                $this->handle->setPhase('Score vorbereiten');
                $this->handle->setDetail('Medien laden');
                $this->handle->setMax(max(1, $total));
                $this->handle->setProgress(0);
                $this->handle->setRate('–');
                break;
            case ClusterBuildProgressCallbackInterface::STAGE_SCORING:
                $this->handle->setPhase('Score berechnen');
                $this->handle->setDetail('Heuristiken ausführen');
                $this->handle->setMax(max(1, $total));
                $this->handle->setProgress(0);
                $this->handle->setRate('–');
                break;
            case ClusterBuildProgressCallbackInterface::STAGE_TITLES:
                $this->handle->setPhase('Titel generieren');
                $this->handle->setDetail('Vorlagen anwenden');
                $this->handle->setMax(max(1, $total));
                $this->handle->setProgress(0);
                $this->handle->setRate('–');
                break;
        }
    }

    public function onStageProgress(string $stage, int $processed, int $total, ?string $detail = null): void
    {
        if ($this->handle === null || $stage !== $this->currentStage) {
            return;
        }

        $this->handle->setMax(max(1, $total));

        if ($detail !== null) {
            $this->handle->setDetail($detail);
        }

        if ($stage === ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA) {
            $this->handle->setRate($this->formatRate($processed, 'Medien'));

            return;
        }

        $this->handle->setProgress(min($processed, $this->maxTotal));
        $this->handle->setRate($this->formatRate($processed, 'Cluster'));
    }

    public function onStageFinish(string $stage, int $total): void
    {
        if ($this->handle === null) {
            return;
        }

        if ($stage === ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA) {
            $this->handle->setMax(max(1, $total));
            $this->handle->setRate($this->formatRate($total, 'Medien'));

            return;
        }

        $this->handle->setMax(max(1, $total));
        $this->handle->setProgress(min($total, $this->maxTotal));
        $this->handle->setRate($this->formatRate($total, 'Cluster'));

        if ($stage === ClusterBuildProgressCallbackInterface::STAGE_TITLES) {
            $this->handle->finish();
            $this->finished = true;
        }
    }

    public function finalize(): void
    {
        if ($this->handle !== null && !$this->finished) {
            $this->handle->finish();
            $this->finished = true;
        }
    }

    private function formatRate(int $processed, string $unit): string
    {
        $startedAt = $this->stageStartedAt ?? microtime(true);
        $elapsed   = max(0.000001, microtime(true) - $startedAt);

        return sprintf('Durchsatz: %.1f %s/s', $processed / $elapsed, $unit);
    }
}
