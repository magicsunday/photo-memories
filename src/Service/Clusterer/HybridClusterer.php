<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterBuildProgressCallbackInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressHandleInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\HybridClustererInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\CompositeClusterScorer;

use function array_merge;
use function count;
use function is_array;
use function iterator_to_array;
use function max;
use function min;
use function sprintf;

/**
 * Class HybridClusterer.
 */
final class HybridClusterer implements HybridClustererInterface
{
    /** @var list<ClusterStrategyInterface>|null */
    private ?array $strategiesCache = null;

    /**
     * @param iterable<ClusterStrategyInterface> $strategies
     */
    public function __construct(
        private readonly iterable $strategies,
        private readonly CompositeClusterScorer $scorer,
        private readonly TitleGeneratorInterface $titleGenerator,
    ) {
    }

    /**
     * New API: lifecycle + per-strategy progress.
     *
     * @param list<Media>                                                                         $items
     * @param callable(string $strategy, int $index, int $total)|null                             $onStart
     * @param callable(string $strategy, int $index, int $total)|null                             $onDone
     * @param callable(string $strategy, int $index, int $total): ?ProgressHandleInterface|null   $makeProgressHandle
     *
     * @return list<ClusterDraft>
     */
    public function build(
        array $items,
        ?callable $onStart,
        ?callable $onDone,
        ?callable $makeProgressHandle = null,
        ?ClusterBuildProgressCallbackInterface $progressCallback = null,
    ): array {
        $strategies = $this->getStrategies();
        $total      = count($strategies);
        $context    = Context::fromScope($items);

        $drafts = [[]];
        $idx    = 0;

        foreach ($strategies as $s) {
            ++$idx;

            if ($onStart !== null) {
                $onStart($s->name(), $idx, $total);
            }

            $strategyHandle = null;
            if ($makeProgressHandle !== null) {
                $strategyHandle = $makeProgressHandle($s->name(), $idx, $total);
            }

            if ($strategyHandle !== null && $s instanceof ProgressAwareClusterStrategyInterface) {
                $res = $s->clusterWithProgress(
                    $items,
                    $context,
                    static function (int $done, int $maxSteps, string $stage) use ($strategyHandle): void {
                        if ($maxSteps > 0) {
                            $progress = max(0, min($done, $maxSteps));
                            $strategyHandle->setMax($maxSteps);
                            $strategyHandle->setDetail(sprintf('Schritt %d/%d', $progress, $maxSteps));
                            $strategyHandle->setRate(sprintf('Fortschritt: %d/%d Schritte', $progress, $maxSteps));
                            $strategyHandle->setProgress($progress);
                        } else {
                            $strategyHandle->setDetail($stage);
                            $strategyHandle->setRate('Fortschritt: –');
                        }

                        $strategyHandle->setPhase($stage);
                    },
                );
            } else {
                $res = $s->draft($items, $context);
            }

            if ($res !== []) {
                $drafts[] = $res;
            }

            if ($strategyHandle !== null) {
                $strategyHandle->setPhase('Abgeschlossen');
                $strategyHandle->setDetail(sprintf('%d Cluster erzeugt', count($res)));
                $strategyHandle->setRate('–');
                $strategyHandle->finish();
            }

            if ($onDone !== null) {
                $onDone($s->name(), $idx, $total);
            }
        }

        $drafts = array_merge(...$drafts);

        if ($drafts === []) {
            return [];
        }

        $drafts = $this->scorer->score($drafts, $progressCallback);

        $draftCount = count($drafts);

        if ($progressCallback !== null) {
            $progressCallback->onStageStart(ClusterBuildProgressCallbackInterface::STAGE_TITLES, $draftCount);
        }

        foreach ($drafts as $index => $d) {
            $d->setParam('title', $this->titleGenerator->makeTitle($d));
            $d->setParam('subtitle', $this->titleGenerator->makeSubtitle($d));

            if ($progressCallback !== null) {
                $progressCallback->onStageProgress(
                    ClusterBuildProgressCallbackInterface::STAGE_TITLES,
                    $index + 1,
                    $draftCount,
                    $d->getAlgorithm(),
                );
            }
        }

        if ($progressCallback !== null) {
            $progressCallback->onStageFinish(ClusterBuildProgressCallbackInterface::STAGE_TITLES, $draftCount);
        }

        return $drafts;
    }

    public function countStrategies(): int
    {
        return count($this->getStrategies());
    }

    /** @return list<ClusterStrategyInterface> */
    private function getStrategies(): array
    {
        if ($this->strategiesCache !== null) {
            return $this->strategiesCache;
        }

        $this->strategiesCache = is_array($this->strategies)
            ? $this->strategies
            : iterator_to_array($this->strategies, false);

        return $this->strategiesCache;
    }
}
