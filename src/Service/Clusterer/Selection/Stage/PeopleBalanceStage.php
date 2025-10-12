<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection\Stage;

use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Service\Clusterer\Selection\Support\FaceMetricHelper;

use function array_intersect;
use function array_unique;
use function array_values;
use function count;
use function floor;
use function is_array;
use function is_numeric;
use function max;

/**
 * Balances person coverage while prioritising important cohorts.
 */
final class PeopleBalanceStage implements SelectionStageInterface
{
    /**
     * @param list<int> $importantPersonIds
     * @param list<int> $fallbackPersonIds
     */
    public function __construct(
        private readonly array $importantPersonIds = [],
        private readonly array $fallbackPersonIds = [],
    ) {
    }

    public function getName(): string
    {
        return SelectionTelemetry::REASON_PEOPLE;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $selected              = [];
        $personCount           = [];
        $hasImportantCandidates = $this->hasImportantCandidates($candidates);

        foreach ($candidates as $candidate) {
            /** @var list<int> $persons */
            $persons = $candidate['person_ids'];
            if ($persons === []) {
                $selected[] = $candidate;

                continue;
            }

            $persons = array_values(array_unique($persons));
            if ($this->contains($persons, $this->importantPersonIds)) {
                $selected[] = $candidate;
                $this->register($personCount, $persons);

                continue;
            }

            $nextTotal = count($selected) + 1;
            $shareCap  = $hasImportantCandidates ? 0.5 : 0.4;
            $limit     = $this->personLimit($nextTotal, $shareCap);

            $allowed = false;
            foreach ($persons as $person) {
                if (($personCount[$person] ?? 0) + 1 <= $limit) {
                    $allowed = true;

                    break;
                }
            }

            if (!$allowed && $this->contains($persons, $this->fallbackPersonIds)) {
                $allowed = true;
            }

            if (!$allowed && $this->isGroupFrame($candidate)) {
                $allowed = true;
            }

            if (!$allowed) {
                $telemetry->increment(SelectionTelemetry::REASON_PEOPLE);

                continue;
            }

            $selected[] = $candidate;
            $this->register($personCount, $persons);
        }

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function hasImportantCandidates(array $candidates): bool
    {
        if ($this->importantPersonIds === []) {
            return false;
        }

        foreach ($candidates as $candidate) {
            /** @var list<int> $persons */
            $persons = $candidate['person_ids'];
            if ($this->contains($persons, $this->importantPersonIds)) {
                return true;
            }
        }

        return false;
    }

    private function personLimit(int $nextTotal, float $shareCap): int
    {
        $limit = (int) floor($nextTotal * $shareCap);

        return max(1, $limit);
    }

    /**
     * @param array<int, int> $counts
     * @param list<int>       $persons
     */
    private function register(array &$counts, array $persons): void
    {
        foreach ($persons as $person) {
            $counts[$person] = ($counts[$person] ?? 0) + 1;
        }
    }

    /**
     * @param list<int> $haystack
     * @param list<int> $needles
     */
    private function contains(array $haystack, array $needles): bool
    {
        if ($needles === []) {
            return false;
        }

        return array_intersect($haystack, $needles) !== [];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function isGroupFrame(array $candidate): bool
    {
        $metrics = $candidate['face_metrics'] ?? null;
        if (!is_array($metrics)) {
            return false;
        }

        $count    = (int) ($metrics['count'] ?? 0);
        $coverage = $metrics['largest_coverage'] ?? null;
        if (is_numeric($coverage)) {
            $coverage = (float) $coverage;
        } else {
            $coverage = null;
        }

        return FaceMetricHelper::isGroupShot($count, $coverage);
    }
}
