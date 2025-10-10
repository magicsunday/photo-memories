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

/**
 * Configuration options steering the greedy vacation member selector.
 */
final readonly class VacationSelectionOptions
{
    public int $minimumTotal;

    public function __construct(
        public int $targetTotal = 40,
        public int $maxPerDay = 8,
        public int $timeSlotHours = 3,
        public int $minSpacingSeconds = 900,
        public int $phashMinHamming = 6,
        public int $maxPerStaypoint = 4,
        public float $videoBonus = 0.20,
        public float $faceBonus = 0.10,
        public float $selfiePenalty = 0.15,
        public float $qualityFloor = 0.35,
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

        $this->minimumTotal = $minimumTotal ?? $this->targetTotal;

        if ($this->minimumTotal < 1) {
            throw new InvalidArgumentException('minimumTotal must be at least 1.');
        }

        if ($this->minimumTotal > $this->targetTotal) {
            throw new InvalidArgumentException('minimumTotal must not exceed targetTotal.');
        }
    }
}
