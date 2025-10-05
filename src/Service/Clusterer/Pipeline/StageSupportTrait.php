<?php 

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;

use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sha1;
use function sort;
use function sprintf;

use const SORT_NUMERIC;

/**
 * Shared helper methods for consolidation stages.
 */
trait StageSupportTrait
{
    /**
     * @param list<int> $members
     *
     * @return list<int>
     */
    protected function normalizeMembers(array $members): array
    {
        $unique = array_values(array_unique($members, SORT_NUMERIC));
        sort($unique, SORT_NUMERIC);

        return $unique;
    }

    /**
     * @param list<int> $members
     */
    protected function fingerprint(array $members): string
    {
        return sha1(implode(',', $members));
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    protected function jaccard(array $a, array $b): float
    {
        $ia    = 0;
        $ib    = 0;
        $inter = 0;
        $na    = count($a);
        $nb    = count($b);

        while ($ia < $na && $ib < $nb) {
            $va = $a[$ia];
            $vb = $b[$ib];
            if ($va === $vb) {
                ++$inter;
                ++$ia;
                ++$ib;
                continue;
            }

            if ($va < $vb) {
                ++$ia;
                continue;
            }

            ++$ib;
        }

        $union = $na + $nb - $inter;

        return $union > 0 ? $inter / (float) $union : 0.0;
    }

    protected function computeScore(ClusterDraft $draft, ?array $normalizedMembers = null): float
    {
        $score = (float) ($draft->getParams()['score'] ?? 0.0);
        if ($score > 0.0) {
            return $score;
        }

        $members = $normalizedMembers ?? $this->normalizeMembers($draft->getMembers());

        return (float) count($members);
    }

    protected function hasValidTimeRange(ClusterDraft $draft, int $minValidYear): bool
    {
        $range = $draft->getParams()['time_range'] ?? null;
        if (!is_array($range) || !isset($range['from'], $range['to'])) {
            return false;
        }

        $from = (int) $range['from'];
        $to   = (int) $range['to'];
        if ($from <= 0 || $to <= 0 || $to < $from) {
            return false;
        }

        $minTimestamp = (new DateTimeImmutable(sprintf('%04d-01-01', $minValidYear)))->getTimestamp();

        return $from >= $minTimestamp && $to >= $minTimestamp;
    }

    /**
     * @param array<string,string> $algorithmGroups
     */
    protected function resolveGroup(string $algorithm, array $algorithmGroups, string $defaultGroup): string
    {
        $group = $algorithmGroups[$algorithm] ?? null;
        if (is_string($group) && $group !== '') {
            return $group;
        }

        return $defaultGroup;
    }
}
