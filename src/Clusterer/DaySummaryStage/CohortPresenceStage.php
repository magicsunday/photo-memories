<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Clusterer\Support\PersonSignatureHelper;
use MagicSunday\Memories\Entity\Media;

use function count;
use function is_int;
use function is_array;
use function is_numeric;
use function is_string;
use function ksort;
use function max;
use function min;

use const SORT_NUMERIC;

/**
 * Derives cohort attendance statistics for day summaries.
 */
final class CohortPresenceStage implements DaySummaryStageInterface
{
    /**
     * @var array<int, true>
     */
    private array $importantPersonIdSet;

    /**
     * @param list<int|string> $importantPersonIds
     * @param list<string>     $fallbackPersonNames
     */
    public function __construct(
        private readonly PersonSignatureHelper $personSignatureHelper,
        array $importantPersonIds = [],
        array $fallbackPersonNames = [],
    ) {
        $this->importantPersonIdSet = $this->buildImportantPersonIdSet(
            $importantPersonIds,
            $fallbackPersonNames,
        );
    }

    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        foreach ($days as &$summary) {
            $summary['cohortMembers']       = [];
            $summary['cohortPresenceRatio'] = 0.0;

            $members = $summary['members'] ?? [];
            if (!is_array($members)) {
                continue;
            }

            $totalMembers = count($members);
            if ($totalMembers < 1) {
                continue;
            }

            if ($this->importantPersonIdSet === []) {
                continue;
            }

            $cohortMembers = [];
            $cohortHits    = 0;

            foreach ($members as $media) {
                if (!$media instanceof Media) {
                    continue;
                }

                $personIds = $this->personSignatureHelper->personIds($media);
                if ($personIds === []) {
                    continue;
                }

                $matched = false;
                foreach ($personIds as $personId) {
                    if (!isset($this->importantPersonIdSet[$personId])) {
                        continue;
                    }

                    $cohortMembers[$personId] = ($cohortMembers[$personId] ?? 0) + 1;
                    $matched                  = true;
                }

                if ($matched) {
                    ++$cohortHits;
                }
            }

            if ($cohortMembers !== []) {
                ksort($cohortMembers, SORT_NUMERIC);
            }

            $ratio = $cohortHits > 0 ? $cohortHits / $totalMembers : 0.0;
            $ratio = min(1.0, max(0.0, $ratio));

            $summary['cohortMembers']       = $cohortMembers;
            $summary['cohortPresenceRatio'] = $ratio;
        }

        unset($summary);

        return $days;
    }

    /**
     * @param list<int|string> $importantPersonIds
     * @param list<string>     $fallbackPersonNames
     *
     * @return array<int, true>
     */
    private function buildImportantPersonIdSet(array $importantPersonIds, array $fallbackPersonNames): array
    {
        $idSet = [];

        foreach ($importantPersonIds as $candidate) {
            $value = $this->filterPersonIdCandidate($candidate);
            if ($value === null) {
                continue;
            }

            $idSet[$value] = true;
        }

        foreach ($fallbackPersonNames as $name) {
            if (!is_string($name)) {
                continue;
            }

            $personId = $this->personSignatureHelper->personIdFromName($name);
            if ($personId === null) {
                continue;
            }

            $idSet[$personId] = true;
        }

        return $idSet;
    }

    private function filterPersonIdCandidate(int|string $candidate): ?int
    {
        if (is_int($candidate)) {
            if ($candidate < 1) {
                return null;
            }

            return $candidate;
        }

        if (!is_numeric($candidate)) {
            return null;
        }

        $value = (int) $candidate;
        if ($value < 1) {
            return null;
        }

        return $value;
    }
}
