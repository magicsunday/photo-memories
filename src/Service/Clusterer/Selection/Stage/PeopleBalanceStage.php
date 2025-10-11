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

use function array_intersect;
use function array_unique;
use function array_values;
use function ceil;
use function count;
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

        $selected    = [];
        $personCount = [];

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
            $limit     = max(1, (int) ceil($nextTotal * 0.5));

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
}
