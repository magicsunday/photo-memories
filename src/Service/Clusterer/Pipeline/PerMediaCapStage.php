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

use function count;
use function usort;

/**
 * Enforces a per-media cap per algorithm group.
 */
final class PerMediaCapStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    /** @var array<string,int> */
    private array $priorityMap = [];

    /**
     * @param list<string>         $keepOrder
     * @param array<string,string> $algorithmGroups
     */
    public function __construct(
        private readonly int $perMediaCap,
        array $keepOrder,
        private readonly array $algorithmGroups,
        private readonly string $defaultAlgorithmGroup,
    ) {
        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Begrenzung je Medium';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);
        if ($this->perMediaCap <= 0 || $total === 0) {
            if ($progress !== null) {
                $progress($total, $total);
            }

            return $drafts;
        }

        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<array{draft: ClusterDraft, members: list<int>, score: float, priority: int, size: int, group: string, index: int}> $items */
        $items = [];
        /** @var list<array{index: int, draft: ClusterDraft}> $subStories */
        $subStories = [];

        foreach ($drafts as $index => $draft) {
            if ($this->isSubStory($draft)) {
                $subStories[] = ['index' => $index, 'draft' => $draft];
                continue;
            }

            $members = $this->normalizeMembers($draft->getMembers());
            $items[] = [
                'draft'    => $draft,
                'members'  => $members,
                'score'    => $this->computeScore($draft, $members),
                'priority' => (int) ($this->priorityMap[$draft->getAlgorithm()] ?? 0),
                'size'     => count($members),
                'group'    => $this->resolveGroup($draft->getAlgorithm(), $this->algorithmGroups, $this->defaultAlgorithmGroup),
                'index'    => $index,
            ];
        }

        usort($items, static function (array $a, array $b): int {
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

        /** @var array<string,array<int,int>> $assignments */
        $assignments = [];
        /** @var list<ClusterDraft> $result */
        $result    = [];
        $processed = 0;

        foreach ($items as $item) {
            ++$processed;
            if ($progress !== null && ($processed % 200) === 0) {
                $progress($processed, $total);
            }

            $group = $item['group'];
            $assignments[$group] ??= [];
            $allowed = true;
            foreach ($item['members'] as $member) {
                $count = $assignments[$group][$member] ?? 0;
                if ($count >= $this->perMediaCap) {
                    $allowed = false;
                    break;
                }
            }

            if (!$allowed) {
                continue;
            }

            foreach ($item['members'] as $member) {
                $assignments[$group][$member] = ($assignments[$group][$member] ?? 0) + 1;
            }

            $result[] = $item['draft'];
        }

        if ($subStories !== []) {
            usort($subStories, static function (array $a, array $b): int {
                return $a['index'] <=> $b['index'];
            });

            foreach ($subStories as $subStory) {
                $result[] = $subStory['draft'];
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        return $result;
    }
}
