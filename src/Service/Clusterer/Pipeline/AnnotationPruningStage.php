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

use function array_map;
use function count;

/**
 * Removes annotation-only clusters that do not contribute enough unique media.
 */
final class AnnotationPruningStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    /** @var array<string,bool> $annotateOnlySet */
    private array $annotateOnlySet = [];

    /**
     * @param list<string>        $annotateOnly
     * @param array<string,float> $minUniqueShare
     */
    public function __construct(
        private readonly array $annotateOnly,
        private readonly array $minUniqueShare,
    ) {
        foreach ($annotateOnly as $algorithm) {
            $this->annotateOnlySet[$algorithm] = true;
        }
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
        $total = count($drafts);
        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<list<int>> $normalized */
        $normalized = array_map(
            static fn (ClusterDraft $draft): array => $this->normalizeMembers($draft->getMembers()),
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
                continue;
            }

            foreach ($members as $member) {
                $memberUse[$member] = ($memberUse[$member] ?? 0) + 1;
            }

            $result[] = $draft;
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        return $result;
    }

    private function isAnnotateOnly(string $algorithm): bool
    {
        return isset($this->annotateOnlySet[$algorithm]);
    }
}
