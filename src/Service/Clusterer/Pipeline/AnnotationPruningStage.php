<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function count;
use function max;

/**
 * Removes annotation-only clusters that do not contribute enough unique media.
 */
final class AnnotationPruningStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    /** @var array<string,bool> */
    private array $annotateOnlySet = [];

    /**
     * @param list<string>        $annotateOnly
     * @param array<string,float> $minUniqueShare
     */
    public function __construct(
        array $annotateOnly,
        private readonly array $minUniqueShare,
        private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
        $this->annotateOnlySet = array_fill_keys($annotateOnly, true);
    }

    public function getLabel(): string
    {
        return 'Annotation prüfen';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total              = count($drafts);
        $annotateCandidates = 0;
        $keptAnnotations    = 0;
        $droppedAnnotations = 0;

        foreach ($drafts as $draft) {
            if ($this->isAnnotateOnly($draft->getAlgorithm())) {
                ++$annotateCandidates;
            }
        }

        $this->emitMonitoring('selection_start', [
            'pre_count'            => $total,
            'annotate_candidates'  => $annotateCandidates,
            'annotate_algorithms'  => array_keys($this->annotateOnlySet),
            'min_unique_share_map' => array_keys($this->minUniqueShare),
        ]);

        if ($total === 0) {
            if ($progress !== null) {
                $progress(0, 0);
            }

            $this->emitMonitoring('selection_completed', [
                'pre_count'           => 0,
                'post_count'          => 0,
                'dropped_count'       => 0,
                'kept_annotations'    => 0,
                'dropped_annotations' => 0,
            ]);

            return $drafts;
        }

        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<list<int>> $normalized */
        $normalized = array_map(
            fn (ClusterDraft $draft): array => $this->normalizeMembers($draft->getMembers()),
            $drafts,
        );

        /** @var array<int,int> $memberUse */
        $memberUse = [];
        foreach ($drafts as $index => $draft) {
            if ($this->isAnnotateOnly($draft->getAlgorithm())) {
                continue;
            }

            foreach ($normalized[$index] as $member) {
                $memberUse[$member] = ($memberUse[$member] ?? 0) + 1;
            }
        }

        /** @var list<ClusterDraft> $result */
        $result = [];
        foreach ($drafts as $index => $draft) {
            if ($progress !== null && (($index + 1) % 200) === 0) {
                $progress($index + 1, $total);
            }

            if (!$this->isAnnotateOnly($draft->getAlgorithm())) {
                $result[] = $draft;
                continue;
            }

            $members = $normalized[$index];
            $size    = count($members);
            if ($size === 0) {
                ++$droppedAnnotations;
                continue;
            }

            $unique = 0;
            foreach ($members as $member) {
                $usage = (int) ($memberUse[$member] ?? 0);
                if ($usage === 0) {
                    ++$unique;
                }
            }

            $share      = $unique / (float) $size;
            $minAllowed = (float) ($this->minUniqueShare[$draft->getAlgorithm()] ?? 0.0);
            if ($share < $minAllowed) {
                ++$droppedAnnotations;
                continue;
            }

            foreach ($members as $member) {
                $memberUse[$member] = ($memberUse[$member] ?? 0) + 1;
            }

            ++$keptAnnotations;
            $result[] = $draft;
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        $postCount = count($result);

        $this->emitMonitoring('selection_completed', [
            'pre_count'           => $total,
            'post_count'          => $postCount,
            'dropped_count'       => max(0, $total - $postCount),
            'kept_annotations'    => $keptAnnotations,
            'dropped_annotations' => $droppedAnnotations,
        ]);

        return $result;
    }

    private function isAnnotateOnly(string $algorithm): bool
    {
        return isset($this->annotateOnlySet[$algorithm]);
    }

    /**
     * @param array<string, int|float|string|list<string>|null> $payload
     */
    private function emitMonitoring(string $event, array $payload): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('annotation_pruning', $event, $payload);
    }
}
