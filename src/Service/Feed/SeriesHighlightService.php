<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use MagicSunday\Memories\Clusterer\ClusterDraft;

use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_int;
use function is_numeric;
use function sprintf;
use function sort;

use const SORT_NUMERIC;

/**
 * Enriches cluster drafts with consolidated year series highlights.
 */
final class SeriesHighlightService
{
    public function apply(ClusterDraft $cluster): void
    {
        $params = $cluster->getParams();
        $years  = $this->normaliseYears($params['years'] ?? null);

        if ($years === []) {
            return;
        }

        $cluster->setParam('years', $years);

        $cluster->setParam('series_highlights', [
            'jahre'        => $years,
            'anzahl'       => count($years),
            'erstesJahr'   => $years[0],
            'letztesJahr'  => $years[count($years) - 1],
            'konsekutiv'   => $this->isConsecutive($years),
            'beschreibung' => $this->buildDescription($years),
        ]);
    }

    /**
     * @param mixed $value raw years payload from the cluster parameters
     *
     * @return list<int>
     */
    private function normaliseYears(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $years = [];
        foreach ($value as $candidate) {
            if (is_int($candidate)) {
                $year = $candidate;
            } elseif (is_numeric($candidate)) {
                $year = (int) $candidate;
            } else {
                continue;
            }

            if ($year <= 0) {
                continue;
            }

            $years[] = $year;
        }

        if ($years === []) {
            return [];
        }

        /** @var list<int> $unique */
        $unique = array_values(array_unique($years, SORT_NUMERIC));
        sort($unique, SORT_NUMERIC);

        return $unique;
    }

    /**
     * @param list<int> $years
     */
    private function isConsecutive(array $years): bool
    {
        $count = count($years);
        if ($count < 2) {
            return true;
        }

        for ($index = 1; $index < $count; ++$index) {
            if (($years[$index] - $years[$index - 1]) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $years
     */
    private function buildDescription(array $years): string
    {
        $label = $this->formatYearList($years);
        $count = count($years);

        return sprintf('%s (%d %s)', $label, $count, $count === 1 ? 'Jahr' : 'Jahre');
    }

    /**
     * @param list<int> $years
     */
    private function formatYearList(array $years): string
    {
        $count = count($years);
        if ($count === 1) {
            return (string) $years[0];
        }

        if ($this->isConsecutive($years)) {
            return sprintf('%d – %d', $years[0], $years[$count - 1]);
        }

        if ($count === 2) {
            return sprintf('%d & %d', $years[0], $years[1]);
        }

        $head = array_slice($years, 0, -1);
        $tail = $years[$count - 1];

        return sprintf('%s & %d', implode(', ', $head), $tail);
    }
}
