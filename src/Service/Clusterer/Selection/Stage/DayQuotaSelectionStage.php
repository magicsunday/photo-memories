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

use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function sort;

/**
 * Enforces per-day quotas derived from the policy runtime context.
 */
final class DayQuotaSelectionStage implements SelectionStageInterface
{
    public function getName(): string
    {
        return SelectionTelemetry::REASON_DAY_QUOTA;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $quotas = $policy->getDayQuotas();
        if ($quotas === []) {
            return $candidates;
        }

        $selected = [];
        $counts   = [];
        $history  = [];

        $total = count($candidates);
        for ($index = 0; $index < $total; $index++) {
            $candidate = $candidates[$index];
            $day = $candidate['day'] ?? null;
            if (!is_string($day) || $day === '') {
                $selected[] = $candidate;

                continue;
            }

            $limit = $quotas[$day] ?? null;
            if (is_int($limit) && $limit >= 0 && ($counts[$day] ?? 0) >= $limit) {
                $telemetry->increment(SelectionTelemetry::REASON_DAY_QUOTA);

                continue;
            }

            $personIds         = $this->extractPersonIds($candidate);
            $repeatedSignature = $this->repeatedSignature($history[$day] ?? []);
            $signature         = $this->normalisePersonSignature($personIds);

            if ($signature !== null && $signature === $repeatedSignature) {
                $replacementIndex = $this->findAlternativeIndex(
                    $candidates,
                    $index + 1,
                    $day,
                    $repeatedSignature,
                );

                if ($replacementIndex !== null) {
                    $replacement                = $candidates[$replacementIndex];
                    $candidates[$replacementIndex] = $candidate;
                    $candidate                  = $replacement;
                    $personIds                  = $this->extractPersonIds($candidate);
                    $signature                  = $this->normalisePersonSignature($personIds);
                }
            }

            $selected[]       = $candidate;
            $counts[$day] = ($counts[$day] ?? 0) + 1;
            $history[$day] = $this->recordHistory($history[$day] ?? [], $personIds);
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return list<int>
     */
    private function extractPersonIds(array $candidate): array
    {
        $persons = $candidate['person_ids'] ?? null;
        if (!is_array($persons)) {
            return [];
        }

        $ids = [];
        foreach ($persons as $person) {
            if (is_int($person)) {
                $ids[] = $person;

                continue;
            }

            if (is_numeric($person)) {
                $ids[] = (int) $person;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $personIds
     */
    private function normalisePersonSignature(array $personIds): ?string
    {
        if ($personIds === []) {
            return null;
        }

        $unique = array_values(array_unique($personIds));
        sort($unique);

        if ($unique === []) {
            return null;
        }

        return implode('-', $unique);
    }

    /**
     * @param list<list<int>> $history
     */
    private function repeatedSignature(array $history): ?string
    {
        if (count($history) < 2) {
            return null;
        }

        $lastTwo = array_slice($history, -2);
        $first   = $this->normalisePersonSignature($lastTwo[0]);
        $second  = $this->normalisePersonSignature($lastTwo[1]);

        if ($first === null || $second === null) {
            return null;
        }

        if ($first !== $second) {
            return null;
        }

        return $first;
    }

    /**
     * @param list<int> $personIds
     *
     * @return list<list<int>>
     */
    private function recordHistory(array $history, array $personIds): array
    {
        $history[] = $personIds;

        if (count($history) > 2) {
            $history = array_slice($history, -2);
        }

        return array_values($history);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function findAlternativeIndex(array $candidates, int $startIndex, string $day, string $repeatedSignature): ?int
    {
        $total = count($candidates);

        for ($index = $startIndex; $index < $total; $index++) {
            $candidateDay = $candidates[$index]['day'] ?? null;
            if (!is_string($candidateDay) || $candidateDay === '' || $candidateDay !== $day) {
                continue;
            }

            $signature = $this->normalisePersonSignature(
                $this->extractPersonIds($candidates[$index])
            );

            if ($signature === $repeatedSignature) {
                continue;
            }

            return $index;
        }

        return null;
    }
}
