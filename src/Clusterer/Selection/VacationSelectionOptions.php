<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Selection;

use InvalidArgumentException;
use function is_finite;
use function max;
use function round;

/**
 * Configuration options steering the greedy vacation member selector.
 */
final readonly class VacationSelectionOptions
{
    public int $minimumTotal;

    public function __construct(
        public int $targetTotal = 40,
        public int $maxPerDay = 5,
        public int $timeSlotHours = 4,
        public int $minSpacingSeconds = 2400,
        public int $phashMinHamming = 9,
        public int $maxPerStaypoint = 2,
        public float $videoBonus = 0.15,
        public float $faceBonus = 0.20,
        public float $selfiePenalty = 0.25,
        public float $qualityFloor = 0.48,
        public bool $enablePeopleBalance = true,
        public float $peopleBalanceWeight = 0.35,
        public float $repeatPenalty = 0.0,
        ?int $minimumTotal = null,
    ) {
        if ($this->targetTotal < 1) {
            throw new InvalidArgumentException('targetTotal must be at least 1.');
        }

        if ($this->maxPerDay < 1) {
            throw new InvalidArgumentException('maxPerDay must be at least 1.');
        }

        if ($this->timeSlotHours < 1 || $this->timeSlotHours > 24) {
            throw new InvalidArgumentException('timeSlotHours must be between 1 and 24.');
        }

        if ($this->minSpacingSeconds < 0) {
            throw new InvalidArgumentException('minSpacingSeconds must not be negative.');
        }

        if ($this->phashMinHamming < 0) {
            throw new InvalidArgumentException('phashMinHamming must not be negative.');
        }

        if ($this->maxPerStaypoint < 1) {
            throw new InvalidArgumentException('maxPerStaypoint must be at least 1.');
        }

        if ($this->videoBonus < 0.0) {
            throw new InvalidArgumentException('videoBonus must not be negative.');
        }

        if ($this->faceBonus < 0.0) {
            throw new InvalidArgumentException('faceBonus must not be negative.');
        }

        if ($this->selfiePenalty < 0.0) {
            throw new InvalidArgumentException('selfiePenalty must not be negative.');
        }

        if ($this->qualityFloor < 0.0 || $this->qualityFloor > 1.0) {
            throw new InvalidArgumentException('qualityFloor must be within [0,1].');
        }

        if ($this->peopleBalanceWeight < 0.0 || $this->peopleBalanceWeight > 1.0) {
            throw new InvalidArgumentException('peopleBalanceWeight must be within [0,1].');
        }

        if (!is_finite($this->repeatPenalty)) {
            throw new InvalidArgumentException('repeatPenalty must be a finite number.');
        }

        $computedMinimum = (int) round($this->targetTotal * 0.6);
        $computedMinimum = max(24, $computedMinimum);

        if ($computedMinimum > $this->targetTotal) {
            $computedMinimum = $this->targetTotal;
        }

        $this->minimumTotal = $minimumTotal ?? $computedMinimum;

        if ($this->minimumTotal < 1) {
            throw new InvalidArgumentException('minimumTotal must be at least 1.');
        }

        if ($this->minimumTotal > $this->targetTotal) {
            throw new InvalidArgumentException('minimumTotal must not exceed targetTotal.');
        }
    }
}
