<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function max;
use function usort;

/**
 * Enforces a per-media cap per algorithm group.
 */
final class PerMediaCapStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    /** @var array<string,int> */
    private array $priorityMap = [];

    private int $defaultPerMediaCap;

    private ?int $perMediaCapOverride = null;

    /**
     * @param list<string>         $keepOrder
     * @param array<string,string> $algorithmGroups
     */
    public function __construct(
        int $perMediaCap,
        array $keepOrder,
        private readonly array $algorithmGroups,
        private readonly string $defaultAlgorithmGroup,
        private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
        $this->defaultPerMediaCap = $perMediaCap;

        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getPerMediaCap(): int
    {
        return $this->perMediaCapOverride ?? $this->defaultPerMediaCap;
    }

    public function setPerMediaCapOverride(?int $perMediaCap): void
    {
        if ($perMediaCap === null) {
            $this->perMediaCapOverride = null;

            return;
        }

        if ($perMediaCap < 0) {
            throw new InvalidArgumentException('Per-media cap must be greater than or equal to 0.');
        }

        $this->perMediaCapOverride = $perMediaCap;
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
        $total             = count($drafts);
        $blockedCandidates = 0;
        $reassignments     = 0;
        $perMediaCap       = $this->getPerMediaCap();

        $this->emitMonitoring('selection_start', [
            'pre_count'        => $total,
            'per_media_cap'    => $perMediaCap,
            'group_count'      => count($this->algorithmGroups),
            'default_group'    => $this->defaultAlgorithmGroup,
        ]);

        if ($perMediaCap <= 0 || $total === 0) {
            if ($progress !== null) {
                $progress($total, $total);
            }

            $this->emitMonitoring('selection_completed', [
                'pre_count'          => $total,
                'post_count'         => $total,
                'dropped_count'      => 0,
                'blocked_candidates' => 0,
                'reassigned_slots'   => 0,
            ]);

            return $drafts;
        }

        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<array{draft: ClusterDraft, members: list<int>, score: float, priority: int, size: int, group: string, index: int, cover: int|null}> $items */
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
                'cover'    => $draft->getCoverMediaId(),
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

        /** @var array<string,array<int,list<int>>> $assignments */
        $assignments = [];
        /** @var list<array{draft: ClusterDraft, members: list<int>, score: float, priority: int, size: int, group: string, index: int, cover: int|null, removed: bool}> $accepted */
        $accepted  = [];
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
            $blockedMembers = [];
            foreach ($item['members'] as $member) {
                $count = isset($assignments[$group][$member]) ? count($assignments[$group][$member]) : 0;
                if ($count >= $perMediaCap) {
                    $allowed = false;
                    $blockedMembers[] = $member;
                    break;
                }
            }

            if (!$allowed) {
                $coverId = $item['cover'];
                if ($coverId !== null && in_array($coverId, $blockedMembers, true)) {
                    $freed = false;
                    if (isset($assignments[$group][$coverId])) {
                        $existingAssignments = $assignments[$group][$coverId];
                        foreach ($existingAssignments as $assignmentIndex) {
                            $assignedItem = $accepted[$assignmentIndex] ?? null;
                            if ($assignedItem === null || $assignedItem['removed']) {
                                continue;
                            }

                            if ($assignedItem['cover'] === $coverId) {
                                continue;
                            }

                            $this->removeAssignment($assignments, $accepted, $assignmentIndex, $reassignments);
                            $freed = true;
                            break;
                        }
                    }

                    if ($freed) {
                        $allowed        = true;
                        $blockedMembers = [];
                        foreach ($item['members'] as $member) {
                            $count = isset($assignments[$group][$member]) ? count($assignments[$group][$member]) : 0;
                            if ($count >= $perMediaCap) {
                                $allowed = false;
                                $blockedMembers[] = $member;
                                break;
                            }
                        }
                    }
                }

                if (!$allowed) {
                    ++$blockedCandidates;
                    continue;
                }
            }

            $acceptedIndex = count($accepted);
            $accepted[]    = $item + ['removed' => false];
            foreach ($item['members'] as $member) {
                $assignments[$group][$member] ??= [];
                $assignments[$group][$member][] = $acceptedIndex;
            }

            if ($accepted[$acceptedIndex]['removed']) {
                continue;
            }
        }

        foreach ($accepted as $acceptedItem) {
            if ($acceptedItem['removed']) {
                continue;
            }

            $result[] = $acceptedItem['draft'];
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

        $postCount = count($result);

        $this->emitMonitoring('selection_completed', [
            'pre_count'          => $total,
            'post_count'         => $postCount,
            'dropped_count'      => max(0, $total - $postCount),
            'blocked_candidates' => $blockedCandidates,
            'reassigned_slots'   => $reassignments,
        ]);

        return $result;
    }

    /**
     * @param array<string,array<int,list<int>>>                                                              $assignments
     * @param list<array{draft: ClusterDraft, members: list<int>, score: float, priority: int, size: int, group: string, index: int, cover: int|null, removed: bool}> $accepted
     */
    private function removeAssignment(array &$assignments, array &$accepted, int $index, int &$reassignments): void
    {
        if (!isset($accepted[$index]) || $accepted[$index]['removed']) {
            return;
        }

        $accepted[$index]['removed'] = true;
        $group                       = $accepted[$index]['group'];

        ++$reassignments;

        foreach ($accepted[$index]['members'] as $member) {
            if (!isset($assignments[$group][$member])) {
                continue;
            }

            $assignments[$group][$member] = array_values(array_filter(
                $assignments[$group][$member],
                static fn (int $assignedIndex): bool => $assignedIndex !== $index,
            ));

            if ($assignments[$group][$member] === []) {
                unset($assignments[$group][$member]);
            }
        }

        if (isset($assignments[$group]) && $assignments[$group] === []) {
            unset($assignments[$group]);
        }
    }

    /**
     * @param array<string, int|float|string|null> $payload
     */
    private function emitMonitoring(string $event, array $payload): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('per_media_cap', $event, $payload);
    }
}
