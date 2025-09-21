<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;

final class HybridClusterer
{
    /** @var list<ClusterStrategyInterface>|null */
    private ?array $strategiesCache = null;

    /**
     * @param iterable<ClusterStrategyInterface> $strategies
     */
    public function __construct(
        private readonly iterable $strategies,
        private readonly Scoring\CompositeClusterScorer $scorer,
        private readonly TitleGeneratorInterface $titleGenerator
    ) {
    }

    /**
     * New API: lifecycle + per-strategy progress.
     *
     * @param list<Media> $items
     * @param callable(string $strategy, int $index, int $total)|null $onDone
     * @param callable(string $strategy): callable(int $cur, int $max, string $stage)|null $makeProgress
     *
     * @return list<ClusterDraft>
     */
    public function build(
        array $items,
        ?callable $onStart,
        ?callable $onDone,
    ): array {
        $strategies = $this->getStrategies();
        $total      = \count($strategies);

        $drafts = [];
        $idx    = 0;

        foreach ($strategies as $s) {
            $idx++;

            if ($onStart !== null) {
                $onStart($s->name(), $idx, $total);
            }

            $res = $s->cluster($items);

            if ($res !== []) {
                /** @var list<ClusterDraft> $drafts */
                $drafts = \array_merge($drafts, $res);
            }

            if ($onDone !== null) {
                $onDone($s->name(), $idx, $total);
            }
        }

        if ($drafts === []) {
            return [];
        }

        $drafts = $this->scorer->score($drafts);

        foreach ($drafts as $d) {
            $d->setParam('title', $this->titleGenerator->makeTitle($d));
            $d->setParam('subtitle', $this->titleGenerator->makeSubtitle($d));
        }

        return $drafts;
    }

    public function countStrategies(): int
    {
        return \count($this->getStrategies());
    }

    /** @return list<ClusterStrategyInterface> */
    private function getStrategies(): array
    {
        if ($this->strategiesCache !== null) {
            return $this->strategiesCache;
        }
        $this->strategiesCache = \is_array($this->strategies)
            ? $this->strategies
            : \iterator_to_array($this->strategies, false);

        return $this->strategiesCache;
    }
}
