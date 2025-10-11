<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Fixtures;

use Closure;
use MagicSunday\Memories\Clusterer\Selection\MemberSelectorInterface;
use MagicSunday\Memories\Clusterer\Selection\SelectionResult;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Entity\Media;

use function array_keys;
use function array_merge;
use function count;
use function sort;
use function is_array;

use const SORT_STRING;

/**
 * Lightweight member selector used in tests to control curated output deterministically.
 */
final class VacationTestMemberSelector implements MemberSelectorInterface
{
    /**
     * @param Closure|null $curationFilter optional callback mutating the curated members and telemetry
     * @param array<string, mixed> $defaultTelemetry telemetry overrides applied after selection
     */
    public function __construct(
        private readonly ?Closure $curationFilter = null,
        private readonly array $defaultTelemetry = [],
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function select(array $daySummaries, array $home, ?VacationSelectionOptions $options = null): SelectionResult
    {
        $options ??= new VacationSelectionOptions();

        $dayOrder = array_keys($daySummaries);
        sort($dayOrder, SORT_STRING);

        $members        = [];
        $dayCounts      = [];
        $targetTotal    = $options->targetTotal;
        $perDayLimit    = $options->maxPerDay;

        foreach ($dayOrder as $day) {
            $summary = $daySummaries[$day];
            foreach ($summary['members'] as $media) {
                $dayCounts[$day] = ($dayCounts[$day] ?? 0) + 1;
                if ($dayCounts[$day] > $perDayLimit) {
                    continue;
                }

                $members[] = $media;
                if (count($members) >= $targetTotal) {
                    break 2;
                }
            }
        }

        $telemetry = array_merge(
            [
                'selected_total'            => count($members),
                'near_duplicate_blocked'    => 0,
                'near_duplicate_replacements' => 0,
                'spacing_rejections'        => 0,
            ],
            $this->defaultTelemetry,
        );

        if ($this->curationFilter !== null) {
            $result = ($this->curationFilter)($members, $daySummaries, $options);
            if (is_array($result)) {
                $members = $result['members'] ?? $members;
                if (isset($result['telemetry']) && is_array($result['telemetry'])) {
                    $telemetry = array_merge($telemetry, $result['telemetry']);
                }
            }
        }

        $telemetry['selected_total'] = count($members);

        return new SelectionResult($members, $telemetry);
    }
}
