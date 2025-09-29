<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use InvalidArgumentException;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;

/**
 * Consolidates overlapping clusters without mutating ClusterDraft.
 *
 * Pipeline:
 *  1) Filter by min size, min score, (optional) valid time_range.
 *  2) Collapse exact duplicates by normalized member fingerprint.
 *  3) Dominance selection by keepOrder:
 *     - iterate algorithms in keepOrder;
 *     - within each, pick clusters greedily (score/priority/size);
 *     - suppress candidates that overlap too much with already selected:
 *         * J >= overlapDropThreshold  => drop
 *         * J >= overlapMergeThreshold => treat as same story => keep earlier winner, drop candidate
 *  4) Demote/Remove "annotation-only" algorithms unless they add value:
 *     - keep only if uniqueShare(candidate vs. selected) >= minUniqueShare[algorithm] (default 0.0)
 *  5) Enforce per-media cap (greedy by score/priority/size).
 */
final class ClusterConsolidationService
{
    /** @var array<string,int> */
    private array $priorityMap = [];

    /** @var array<string,bool> */
    private array $annotateOnlySet = [];

    /**
     * @param float        $minScore               Minimum score to keep a cluster.
     * @param int          $minSize                Minimum members to keep a cluster.
     * @param float        $overlapMergeThreshold  Jaccard threshold: treat as same story (candidate loses).
     * @param float        $overlapDropThreshold   Jaccard threshold: drop candidate (very high overlap).
     * @param int          $perMediaCap            Max accepted clusters per media id (0 disables).
     * @param list<string> $keepOrder              Algorithm dominance order (earlier = stronger).
     * @param list<string> $annotateOnly           Algorithms that should only remain if they add unique value.
     * @param array<string,float> $minUniqueShare  Minimal unique share per annotateOnly algorithm [0..1].
     * @param bool         $requireValidTime       Drop clusters without valid time_range (optional).
     * @param int          $minValidYear           Lower bound year for requireValidTime.
     */
    public function __construct(
        private readonly float $minScore,
        private readonly int $minSize,
        private readonly float $overlapMergeThreshold,
        private readonly float $overlapDropThreshold,
        private readonly int $perMediaCap,
        private readonly array $keepOrder = [],
        private readonly array $annotateOnly = [],
        private readonly array $minUniqueShare = [],
        private readonly bool $requireValidTime = false,
        private readonly int $minValidYear = 1990
    ) {
        if ($this->overlapDropThreshold < $this->overlapMergeThreshold) {
            throw new InvalidArgumentException('overlapDropThreshold must be >= overlapMergeThreshold');
        }

        $base = \count($this->keepOrder);
        for ($p = 0; $p < $base; $p++) {
            $this->priorityMap[$this->keepOrder[$p]] = $base - $p;
        }

        foreach ($this->annotateOnly as $alg) {
            $this->annotateOnlySet[$alg] = true;
        }
    }

    /**
     * Consolidate a list of drafts.
     *
     * @param list<ClusterDraft> $drafts
     * @param callable(int $done, int $max, string $stage):void|null $progress
     * @return list<ClusterDraft>
     */
    public function consolidate(array $drafts, ?callable $progress = null): array
    {
        // ---- 1) Filter & normalize (no mutation of drafts)
        if ($progress !== null) {
            $progress(0, \count($drafts), 'Filtern');
        }

        /** @var list<ClusterDraft> $kept */
        $kept = [];
        /** @var list<list<int>> $normMembers */
        $normMembers = [];

        $i = 0;
        $total = \count($drafts);
        foreach ($drafts as $d) {
            $i++;
            if ($progress !== null && ($i % 200) === 0) {
                $progress($i, $total, 'Filtern');
            }

            if ($this->requireValidTime && !$this->hasValidTimeRange($d)) {
                continue;
            }

            $nm = $this->normalizeMembers($d->getMembers());
            if (\count($nm) < $this->minSize) {
                continue;
            }

            if ($this->scoreOf($d) < $this->minScore) {
                continue;
            }

            $kept[] = $d;
            $normMembers[] = $nm;
        }

        if ($progress !== null) {
            $progress($total, $total, 'Filtern');
        }

        if ($kept === []) {
            return [];
        }

        // ---- 2) Exact duplicate collapse by fingerprint(normMembers)
        if ($progress !== null) {
            $progress(0, \count($kept), 'Exakte Duplikate');
        }

        /** @var array<string,int> $winnerByFp */
        $winnerByFp = [];
        for ($idx = 0, $n = \count($kept); $idx < $n; $idx++) {
            if ($progress !== null && ($idx % 400) === 0) {
                $progress($idx, $n, 'Exakte Duplikate');
            }

            $fp = $this->fingerprint($normMembers[$idx]);
            $current = $winnerByFp[$fp] ?? null;
            if ($current === null || $this->isBetter($kept[$idx], $kept[$current])) {
                $winnerByFp[$fp] = $idx;
            }
        }

        /** @var list<int> $dedupIdx */
        $dedupIdx = \array_values($winnerByFp);

        /** @var list<ClusterDraft> $dedup */
        $dedup = [];
        /** @var list<list<int>> $normDedup */
        $normDedup = [];
        foreach ($dedupIdx as $j) {
            $dedup[] = $kept[$j];
            $normDedup[] = $normMembers[$j];
        }

        if ($progress !== null) {
            $progress(\count($dedup), \count($dedup), 'Exakte Duplikate');
        }

        if (\count($dedup) <= 1) {
            return $this->applyCap($dedup, $normDedup);
        }

        // ---- 3) Dominance selection by keepOrder
        if ($progress !== null) {
            $progress(0, \count($dedup), 'Dominanz');
        }

        // Build algorithm → list of indices map
        /** @var array<string,list<int>> $byAlg */
        $byAlg = [];
        for ($k = 0, $n = \count($dedup); $k < $n; $k++) {
            $alg = $dedup[$k]->getAlgorithm();
            $byAlg[$alg] ??= [];
            $byAlg[$alg][] = $k;
        }

        // Global selection set (indices into $dedup)
        /** @var list<int> $selected */
        $selected = [];

        // Utility: sort comparator inside same algorithm
        $cmp = function (int $ia, int $ib) use ($dedup, $normDedup): int {
            $a = $dedup[$ia];
            $b = $dedup[$ib];
            $sa = $this->scoreOf($a);
            $sb = $this->scoreOf($b);
            if ($sa !== $sb) {
                return $sa < $sb ? 1 : -1;
            }

            $na = \count($normDedup[$ia]);
            $nb = \count($normDedup[$ib]);
            if ($na !== $nb) {
                return $na < $nb ? 1 : -1;
            }

            return 0;
        };

        // Iterate algorithms in keepOrder, then any remaining
        /** @var list<string> $algOrder */
        $algOrder = $this->keepOrder;

        /** @var array<string,bool> $algSeen */
        $algSeen = [];
        foreach ($algOrder as $alg) {
            $algSeen[$alg] = true;
        }

        foreach (\array_keys($byAlg) as $alg) {
            if (isset($algSeen[$alg])) {
                continue;
            }

            $algOrder[] = $alg;
            $algSeen[$alg] = true;
        }

        // Greedy selection
        foreach ($algOrder as $alg) {
            $idxs = $byAlg[$alg] ?? [];
            if ($idxs === []) {
                continue;
            }

            \usort($idxs, $cmp);

            foreach ($idxs as $cand) {
                // compare to already selected
                $reject = false;

                foreach ($selected as $win) {
                    $j = $this->jaccard($normDedup[$cand], $normDedup[$win]);

                    if ($j >= $this->overlapDropThreshold) {
                        // clear dominance: earlier winner stays
                        $reject = true;
                        break;
                    }

                    if ($j >= $this->overlapMergeThreshold) {
                        // same story; keep earlier by keepOrder/score
                        // earlier winner is $win, so candidate loses
                        $reject = true;
                        break;
                    }
                }

                if ($reject) {
                    continue;
                }

                $selected[] = $cand;
            }
        }

        // Materialize list after dominance
        /** @var list<ClusterDraft> $afterDominance */
        $afterDominance = [];
        /** @var list<list<int>> $normAfterDominance */
        $normAfterDominance = [];
        foreach ($selected as $idxSel) {
            $afterDominance[] = $dedup[$idxSel];
            $normAfterDominance[] = $normDedup[$idxSel];
        }

        // ---- 4) Annotation-only demotion with unique-share guard
        if ($progress !== null) {
            $progress(0, \count($afterDominance), 'Annotation prüfen');
        }

        // Build an index of already accepted members for unique-share computation
        /** @var array<int,int> $memberUse */
        $memberUse = [];
        for ($t = 0, $n = \count($afterDominance); $t < $n; $t++) {
            $alg = $afterDominance[$t]->getAlgorithm();
            if ($this->isAnnotateOnly($alg)) {
                continue;
            }

            foreach ($normAfterDominance[$t] as $mid) {
                $memberUse[$mid] = ($memberUse[$mid] ?? 0) + 1;
            }
        }

        /** @var list<ClusterDraft> $passAnnot */
        $passAnnot = [];
        /** @var list<list<int>> $normPassAnnot */
        $normPassAnnot = [];

        for ($t = 0, $n = \count($afterDominance); $t < $n; $t++) {
            $cluster = $afterDominance[$t];
            $members = $normAfterDominance[$t];
            $alg = $cluster->getAlgorithm();

            if (!$this->isAnnotateOnly($alg)) {
                $passAnnot[] = $cluster;
                $normPassAnnot[] = $members;
                continue;
            }

            $size = \count($members);
            if ($size === 0) {
                continue;
            }

            $unique = 0;
            foreach ($members as $mid) {
                $covered = (int) ($memberUse[$mid] ?? 0);
                if ($covered === 0) {
                    $unique++;
                }
            }

            $share = $unique / (float) $size;
            $minShare = (float) ($this->minUniqueShare[$alg] ?? 0.0);

            if ($share >= $minShare) {
                $passAnnot[] = $cluster;
                $normPassAnnot[] = $members;
                foreach ($members as $mid) {
                    $memberUse[$mid] = ($memberUse[$mid] ?? 0) + 1;
                }
            }
        }

        // ---- 5) Per-media cap
        return $this->applyCap($passAnnot, $normPassAnnot);
    }

    /**
     * Enforce per-media cap using greedy selection by score, algorithm priority (from keepOrder), and size.
     *
     * @param list<ClusterDraft> $drafts
     * @return list<ClusterDraft>
     */
    private function applyCap(array $drafts, array $normMembers = []): array
    {
        if ($this->perMediaCap <= 0 || $drafts === []) {
            return $drafts;
        }

        /** @var list<array{draft: ClusterDraft, members: list<int>, score: float, priority: int, size: int}> $items */
        $items = [];
        foreach ($drafts as $idx => $draft) {
            $members = $normMembers[$idx] ?? $this->normalizeMembers($draft->getMembers());
            $items[] = [
                'draft' => $draft,
                'members' => $members,
                'score' => $this->scoreOf($draft),
                'priority' => (int) ($this->priorityMap[$draft->getAlgorithm()] ?? 0),
                'size' => \count($members),
            ];
        }

        \usort($items, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $a['score'] < $b['score'] ? 1 : -1;
            }

            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] < $b['priority'] ? 1 : -1;
            }

            if ($a['size'] !== $b['size']) {
                return $a['size'] < $b['size'] ? 1 : -1;
            }

            return 0;
        });

        /** @var array<int,int> $assign */
        $assign = [];
        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($items as $item) {
            $members = $item['members'];
            $ok = true;

            foreach ($members as $mid) {
                $cnt = $assign[$mid] ?? 0;
                if ($cnt >= $this->perMediaCap) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                foreach ($members as $mid) {
                    $assign[$mid] = ($assign[$mid] ?? 0) + 1;
                }

                $out[] = $item['draft'];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $members
     * @return list<int>
     */
    private function normalizeMembers(array $members): array
    {
        $members = \array_values(\array_unique($members, \SORT_NUMERIC));
        \sort($members, \SORT_NUMERIC);
        return $members;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private function jaccard(array $a, array $b): float
    {
        $ia = 0;
        $ib = 0;
        $inter = 0;
        $na = \count($a);
        $nb = \count($b);

        while ($ia < $na && $ib < $nb) {
            $va = $a[$ia];
            $vb = $b[$ib];
            if ($va === $vb) {
                $inter++;
                $ia++;
                $ib++;
            } elseif ($va < $vb) {
                $ia++;
            } else {
                $ib++;
            }
        }

        $union = $na + $nb - $inter;
        return $union > 0 ? $inter / (float) $union : 0.0;
    }

    /**
     * @param list<int> $members
     */
    private function fingerprint(array $members): string
    {
        return \sha1(\implode(',', $members));
    }

    private function isAnnotateOnly(string $algorithm): bool
    {
        return isset($this->annotateOnlySet[$algorithm]);
    }

    private function scoreOf(ClusterDraft $d): float
    {
        // Prefer explicit score param
        /** @var float $p */
        $p = (float) ($d->getParams()['score'] ?? 0.0);
        if ($p > 0.0) {
            return $p;
        }

        // Fallback: size as score
        return (float) \count($this->normalizeMembers($d->getMembers()));
    }

    private function hasValidTimeRange(ClusterDraft $d): bool
    {
        $tr = $d->getParams()['time_range'] ?? null;
        if (!\is_array($tr) || !isset($tr['from'], $tr['to'])) {
            return false;
        }

        $from = (int) $tr['from'];
        $to   = (int) $tr['to'];
        if ($from <= 0 || $to <= 0 || $to < $from) {
            return false;
        }

        $minTs = (new DateTimeImmutable(\sprintf('%04d-01-01', $this->minValidYear)))->getTimestamp();
        return $from >= $minTs && $to >= $minTs;
    }

    /**
     * Is A better than B? Uses score, keepOrder priority, then size.
     */
    private function isBetter(ClusterDraft $a, ClusterDraft $b): bool
    {
        $sa = $this->scoreOf($a);
        $sb = $this->scoreOf($b);
        if ($sa !== $sb) {
            return $sa > $sb;
        }

        $pa = (int) ($this->priorityMap[$a->getAlgorithm()] ?? 0);
        $pb = (int) ($this->priorityMap[$b->getAlgorithm()] ?? 0);

        if ($pa !== $pb) {
            return $pa > $pb;
        }

        return \count($this->normalizeMembers($a->getMembers()))
            >= \count($this->normalizeMembers($b->getMembers()));
    }
}
