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
use function is_array;
use function is_string;
use function strcmp;
use function usort;

/**
 * Tags nested clusters on their preferred parent while retaining all drafts.
 */
final class NestingResolverStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;
    use ClusterPriorityResolverTrait;

    /** @var array<string, int> */
    private array $priorityMap = [];

    /**
     * @param list<string> $keepOrder
     */
    public function __construct(array $keepOrder)
    {
        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Verschachtelung';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);
        if ($total <= 1) {
            if ($progress !== null) {
                $progress($total, $total);
            }

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

        /** @var array<int,int> $parentByChild */
        $parentByChild = [];

        for ($i = 0; $i < $total; ++$i) {
            for ($j = $i + 1; $j < $total; ++$j) {
                $normalizedLeft  = $normalized[$i];
                $normalizedRight = $normalized[$j];

                $leftInsideRight  = $this->isSubset($normalizedLeft, $normalizedRight);
                $rightInsideLeft  = $leftInsideRight ? false : $this->isSubset($normalizedRight, $normalizedLeft);

                if (!$leftInsideRight && !$rightInsideLeft) {
                    continue;
                }

                $preferLeft = $this->preferLeft(
                    $drafts[$i],
                    $normalizedLeft,
                    $drafts[$j],
                    $normalizedRight,
                    $this->priorityMap,
                );

                $parentIndex = $preferLeft ? $i : $j;
                $childIndex  = $preferLeft ? $j : $i;

                $currentParent = $parentByChild[$childIndex] ?? null;
                if ($currentParent === null) {
                    $parentByChild[$childIndex] = $parentIndex;
                    continue;
                }

                if ($currentParent === $parentIndex) {
                    continue;
                }

                $shouldReplace = $this->preferLeft(
                    $drafts[$parentIndex],
                    $normalized[$parentIndex],
                    $drafts[$currentParent],
                    $normalized[$currentParent],
                    $this->priorityMap,
                );

                if ($shouldReplace) {
                    $parentByChild[$childIndex] = $parentIndex;
                }
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        /** @var array<int,list<array{algorithm: string, priority: int, score: float, member_count: int, fingerprint: string, classification?: string}>> $subStoriesByParent */
        $subStoriesByParent = [];

        foreach ($parentByChild as $childIndex => $parentIndex) {
            $child       = $drafts[$childIndex];
            $parent      = $drafts[$parentIndex];
            $childMember = $normalized[$childIndex];
            $parentMembers = $normalized[$parentIndex];

            $metadata = [
                'algorithm'    => $child->getAlgorithm(),
                'priority'     => (int) ($this->priorityMap[$child->getAlgorithm()] ?? 0),
                'score'        => $this->computeScore($child, $childMember),
                'member_count' => count($childMember),
                'fingerprint'  => $this->fingerprint($childMember),
            ];

            $classification = $child->getParams()['classification'] ?? null;
            if (is_string($classification) && $classification !== '') {
                $metadata['classification'] = $classification;
            }

            $subStoriesByParent[$parentIndex] ??= [];
            $subStoriesByParent[$parentIndex][] = $metadata;

            $child->setParam('is_sub_story', true);
            $child->setParam('sub_story_priority', $metadata['priority']);
            $child->setParam('sub_story_of', [
                'algorithm'   => $parent->getAlgorithm(),
                'fingerprint' => $this->fingerprint($parentMembers),
                'priority'    => (int) ($this->priorityMap[$parent->getAlgorithm()] ?? 0),
            ]);
        }

        foreach ($subStoriesByParent as $parentIndex => $chapters) {
            usort($chapters, static function (array $a, array $b): int {
                if ($a['priority'] !== $b['priority']) {
                    return $a['priority'] < $b['priority'] ? 1 : -1;
                }

                if ($a['score'] !== $b['score']) {
                    return $a['score'] < $b['score'] ? 1 : -1;
                }

                if ($a['member_count'] !== $b['member_count']) {
                    return $a['member_count'] < $b['member_count'] ? 1 : -1;
                }

                return strcmp($a['fingerprint'], $b['fingerprint']);
            });

            $parentDraft = $drafts[$parentIndex];
            $existing    = $parentDraft->getParams()['sub_stories'] ?? null;
            $merged      = [];

            if (is_array($existing)) {
                foreach ($existing as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $merged[] = $entry;
                }
            }

            foreach ($chapters as $chapter) {
                $merged[] = $chapter;
            }

            $parentDraft->setParam('has_sub_stories', true);
            $parentDraft->setParam('sub_stories', $merged);
        }

        return $drafts;
    }

    /**
     * @param list<int> $subset
     * @param list<int> $superset
     */
    private function isSubset(array $subset, array $superset): bool
    {
        $subsetCount   = count($subset);
        $supersetCount = count($superset);
        if ($subsetCount === 0 || $subsetCount > $supersetCount) {
            return false;
        }

        $i = 0;
        $j = 0;

        while ($i < $subsetCount && $j < $supersetCount) {
            $subsetValue   = $subset[$i];
            $supersetValue = $superset[$j];

            if ($subsetValue === $supersetValue) {
                ++$i;
                ++$j;
                continue;
            }

            if ($subsetValue > $supersetValue) {
                ++$j;
                continue;
            }

            return false;
        }

        return $i === $subsetCount;
    }
}
