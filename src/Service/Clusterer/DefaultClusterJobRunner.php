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
use DateTimeZone;
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
use MagicSunday\Memories\Service\Clusterer\ConsoleProgressReporter;
use MagicSunday\Memories\Service\Clusterer\Debug\VacationDebugContext;
use MagicSunday\Memories\Service\Clusterer\ClusterJobTelemetry;
use MagicSunday\Memories\Service\Clusterer\ClusterSummary;
use MagicSunday\Memories\Service\Clusterer\ClusterSummaryTimeRange;
use Throwable;

use function count;
use function array_slice;
use function max;
use function min;
use function microtime;
use function is_array;
use function is_numeric;
use function is_int;
use function is_string;
use function usort;
use function sprintf;

/**
 * Class DefaultClusterJobRunner.
 */
final readonly class DefaultClusterJobRunner implements ClusterJobRunnerInterface
{
    private const TOP_CLUSTER_SUMMARY_LIMIT = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HybridClustererInterface $clusterer,
        private ClusterConsolidatorInterface $consolidator,
        private ClusterPersistenceInterface $persistence,
        private ?VacationDebugContext $vacationDebugContext = null,
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
            return new ClusterJobResult(
                0,
                0,
                0,
                0,
                0,
                0,
                $options->isDryRun(),
                ClusterJobTelemetry::fromStageCounts(0, 0),
            );
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
            return new ClusterJobResult(
                $total,
                0,
                0,
                0,
                0,
                0,
                $options->isDryRun(),
                ClusterJobTelemetry::fromStageCounts(0, 0),
            );
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
                return new ClusterJobResult(
                    $total,
                    $loadedCount,
                    0,
                    0,
                    0,
                    0,
                    $options->isDryRun(),
                    ClusterJobTelemetry::fromStageCounts(0, 0),
                );
            }
        }

        $deleted = 0;

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
            if ($options->shouldReplace() && !$options->isDryRun()) {
                $connection = $this->entityManager->getConnection();
                $this->entityManager->beginTransaction();

                try {
                    $deleted = $this->persistence->deleteAll();
                    $this->entityManager->commit();
                } catch (Throwable $exception) {
                    if ($connection->isTransactionActive()) {
                        $this->entityManager->rollback();
                    }

                    throw $exception;
                }
            }

            return new ClusterJobResult(
                $total,
                $loadedCount,
                0,
                0,
                0,
                $deleted,
                $options->isDryRun(),
                ClusterJobTelemetry::fromStageCounts(0, 0),
            );
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

        if ($options->isVacationDebugEnabled()) {
            $this->emitVacationDebug($progressReporter, $options, $drafts, $draftCount, $consolidatedCount);
        }

        if ($consolidatedCount === 0) {
            if ($options->shouldReplace() && !$options->isDryRun()) {
                $connection = $this->entityManager->getConnection();
                $this->entityManager->beginTransaction();

                try {
                    $deleted = $this->persistence->deleteAll();
                    $this->entityManager->commit();
                } catch (Throwable $exception) {
                    if ($connection->isTransactionActive()) {
                        $this->entityManager->rollback();
                    }

                    throw $exception;
                }
            }

            return new ClusterJobResult(
                $total,
                $loadedCount,
                $draftCount,
                0,
                0,
                $deleted,
                $options->isDryRun(),
                ClusterJobTelemetry::fromStageCounts($draftCount, 0),
            );
        }

        $telemetry = $this->createTelemetry($draftCount, $consolidatedCount, $drafts);

        $persistHandle = $progressReporter->create(
            $options->isDryRun() ? 'Persistieren (Trockenlauf)' : 'Persistieren',
            '💾 Speichern',
            $consolidatedCount,
        );
        $persistStart = microtime(true);

        $persisted = 0;
        $stream = (static function (array $consolidated): iterable {
            foreach ($consolidated as $draft) {
                yield $draft;
            }
        })($drafts);

        if ($options->isDryRun()) {
            foreach ($stream as $_) {
                ++$persisted;
                $persistHandle->setRate($this->formatRate($persisted, $persistStart, 'Cluster'));
                $persistHandle->advance();
            }
        } else {
            $onPersisted = function (int $persistedNow) use (&$persisted, $persistHandle, $persistStart): void {
                $persisted += $persistedNow;
                $persistHandle->setRate($this->formatRate($persisted, $persistStart, 'Cluster'));
                $persistHandle->advance($persistedNow);
            };

            if ($options->shouldReplace()) {
                $connection = $this->entityManager->getConnection();
                $this->entityManager->beginTransaction();

                try {
                    $deleted = $this->persistence->deleteAll();
                    $persisted = $this->persistence->persistStreaming($stream, $onPersisted);
                    $this->entityManager->commit();
                } catch (Throwable $exception) {
                    if ($connection->isTransactionActive()) {
                        $this->entityManager->rollback();
                    }

                    throw $exception;
                }
            } else {
                $persisted = $this->persistence->persistStreaming($stream, $onPersisted);
            }
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
            $telemetry,
        );
    }

    private function createTelemetry(int $draftCount, int $consolidatedCount, array $consolidatedDrafts): ClusterJobTelemetry
    {
        return ClusterJobTelemetry::fromStageCounts(
            $draftCount,
            $consolidatedCount,
            $this->createTopClusterSummaries($consolidatedDrafts, self::TOP_CLUSTER_SUMMARY_LIMIT),
        );
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterSummary>
     */
    private function createTopClusterSummaries(array $drafts, int $limit): array
    {
        if ($drafts === [] || $limit <= 0) {
            return [];
        }

        $sortedDrafts = $drafts;
        usort(
            $sortedDrafts,
            static function (ClusterDraft $left, ClusterDraft $right): int {
                $leftParams  = $left->getParams();
                $rightParams = $right->getParams();

                $leftScore  = is_numeric($leftParams['score'] ?? null) ? (float) $leftParams['score'] : 0.0;
                $rightScore = is_numeric($rightParams['score'] ?? null) ? (float) $rightParams['score'] : 0.0;

                return $rightScore <=> $leftScore;
            }
        );

        $topDrafts = array_slice($sortedDrafts, 0, $limit);

        $summaries = [];
        foreach ($topDrafts as $draft) {
            $summaries[] = $this->createClusterSummary($draft);
        }

        return $summaries;
    }

    private function createClusterSummary(ClusterDraft $draft): ClusterSummary
    {
        $params = $draft->getParams();

        $score = null;
        if (is_numeric($params['score'] ?? null)) {
            $score = (float) $params['score'];
        }

        $timeRange = null;
        $timeRangeParams = $params['time_range'] ?? null;
        if (
            is_array($timeRangeParams)
            && isset($timeRangeParams['from'], $timeRangeParams['to'])
            && is_numeric($timeRangeParams['from'])
            && is_numeric($timeRangeParams['to'])
        ) {
            $timeRange = new ClusterSummaryTimeRange(
                $this->createUtcDateTime((int) $timeRangeParams['from']),
                $this->createUtcDateTime((int) $timeRangeParams['to']),
            );
        }

        $rawCount     = count($draft->getMembers());
        $curatedCount = $rawCount;
        $policyKey    = null;

        $memberQuality = $params['member_quality'] ?? null;
        if (is_array($memberQuality)) {
            $ordered = $memberQuality['ordered'] ?? null;
            if (is_array($ordered)) {
                $curatedCount = 0;
                foreach ($ordered as $value) {
                    if (is_int($value)) {
                        ++$curatedCount;

                        continue;
                    }

                    if (is_string($value) && is_numeric($value)) {
                        ++$curatedCount;
                    }
                }
            }

            $summary = $memberQuality['summary'] ?? null;
            if (is_array($summary)) {
                $persisted = $summary['members_persisted'] ?? null;
                if (is_numeric($persisted)) {
                    $rawCount = (int) $persisted;
                }

                $overlayCount = $summary['curated_overlay_count'] ?? null;
                if (is_numeric($overlayCount)) {
                    $curatedCount = (int) $overlayCount;
                }

                $selectionCounts = $summary['selection_counts'] ?? null;
                if (is_array($selectionCounts)) {
                    if (is_numeric($selectionCounts['raw'] ?? null)) {
                        $rawCount = (int) $selectionCounts['raw'];
                    }

                    if (is_numeric($selectionCounts['curated'] ?? null)) {
                        $curatedCount = (int) $selectionCounts['curated'];
                    }
                }

                $policyCandidate = $summary['selection_policy'] ?? ($summary['selection_profile'] ?? null);
                if (is_string($policyCandidate) && $policyCandidate !== '') {
                    $policyKey = $policyCandidate;
                } elseif (isset($summary['selection_telemetry']) && is_array($summary['selection_telemetry'])) {
                    $telemetryPolicy = $summary['selection_telemetry']['policy']['profile'] ?? null;
                    if (is_string($telemetryPolicy) && $telemetryPolicy !== '') {
                        $policyKey = $telemetryPolicy;
                    }
                }
            }
        }

        if ($policyKey === null) {
            $memberSelection = $params['member_selection'] ?? null;
            if (is_array($memberSelection)) {
                $candidate = $memberSelection['profile'] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    $policyKey = $candidate;
                }
            }
        }

        return new ClusterSummary(
            $draft->getAlgorithm(),
            $draft->getStoryline(),
            max(0, $rawCount),
            max(0, $curatedCount),
            $policyKey,
            $score,
            $timeRange,
        );
    }

    private function createUtcDateTime(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param list<ClusterDraft> $drafts
     */
    private function emitVacationDebug(
        ProgressReporterInterface $progressReporter,
        ClusterJobOptions $options,
        array $drafts,
        int $draftCount,
        int $consolidatedCount,
    ): void {
        if (
            $this->vacationDebugContext === null
            || !$this->vacationDebugContext->isEnabled()
            || !$options->isVacationDebugEnabled()
        ) {
            return;
        }

        if (!$progressReporter instanceof ConsoleProgressReporter) {
            return;
        }

        $segments = $this->vacationDebugContext->getSegments();
        $io       = $progressReporter->getStyle();

        $io->section('🏖️ Urlaub Debug');

        if ($segments === []) {
            $io->text('Keine Urlaubssegmente erkannt.');
        } else {
            $segmentRows = [];
            foreach ($segments as $segment) {
                $segmentRows[] = [
                    $segment['start_date'],
                    $segment['end_date'],
                    (string) $segment['away_days'],
                    (string) $segment['members'],
                    (string) $segment['center_count'],
                    sprintf('%.1f', $segment['radius_km']),
                    sprintf('%.2f', $segment['density']),
                ];
            }

            $io->table(['Start', 'Ende', 'Away-Tage', 'Medien', 'Zentren', 'Radius (km)', 'Dichte'], $segmentRows);
        }

        $io->writeln(sprintf('Gebildet → Konsolidiert: %d → %d', $draftCount, $consolidatedCount));

        if ($consolidatedCount === 0) {
            $io->text('Keine Cluster verfügbar.');

            return;
        }

        $clusterRows = [];
        foreach ($this->createTopClusterSummaries($drafts, self::TOP_CLUSTER_SUMMARY_LIMIT) as $summary) {
            $score = $summary->getScore();
            $range = '–';
            $timeRange = $summary->getTimeRange();
            if ($timeRange instanceof ClusterSummaryTimeRange) {
                $range = $this->formatTimeRange(
                    $timeRange->getFrom()->getTimestamp(),
                    $timeRange->getTo()->getTimestamp(),
                );
            }

            $clusterRows[] = [
                $summary->getAlgorithm(),
                $summary->getStoryline(),
                (string) $summary->getMemberCount(),
                $score !== null ? sprintf('%.2f', $score) : '–',
                $range,
            ];
        }

        $io->table(['Algorithmus', 'Storyline', 'Mitglieder', 'Score', 'Zeitraum'], $clusterRows);
    }

    private function formatTimeRange(int $from, int $to): string
    {
        $timezone = new DateTimeZone('UTC');
        $fromDate = (new DateTimeImmutable('@' . $from))->setTimezone($timezone)->format('Y-m-d');
        $toDate   = (new DateTimeImmutable('@' . $to))->setTimezone($timezone)->format('Y-m-d');

        if ($fromDate === $toDate) {
            return $fromDate;
        }

        return sprintf('%s → %s', $fromDate, $toDate);
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
            $this->handle->setProgress(min($processed, $total));
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
            $this->handle->setProgress(min($total, $this->maxTotal));
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
