<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection;

use InvalidArgumentException;

/**
 * Immutable value object describing the tunable knobs of the selector.
 */
final class SelectionPolicy
{
    /**
     * @param string        $profileKey         semantic key used for telemetry
     * @param int           $targetTotal        desired number of curated members
     * @param int           $minimumTotal       lower bound after relaxations
     * @param int|null      $maxPerDay          optional per-day cap
     * @param float|null    $timeSlotHours      optional slot size per day
     * @param int           $minSpacingSeconds  minimum spacing in seconds between picks
     * @param int           $phashMinHamming    minimum perceptual hash distance
     * @param int|null      $maxPerStaypoint    optional staypoint cap
     * @param int|null      $relaxedMaxPerStaypoint optional staypoint cap used for policy relaxations
     * @param float         $qualityFloor       minimum quality score accepted
     * @param float         $videoBonus         additive score boost for videos
     * @param float         $faceBonus          additive score boost for media with faces
     * @param float         $selfiePenalty      subtractive score for selfie-like scenes
     * @param int|null      $maxPerYear         per-year cap for over-years memories
     * @param int|null      $maxPerBucket       optional generic bucket cap
     * @param float|null    $videoHeavyBonus    optional bonus applied when cluster is video heavy
     */
    public function __construct(
        private readonly string $profileKey,
        private readonly int $targetTotal,
        private readonly int $minimumTotal,
        private readonly ?int $maxPerDay,
        private readonly ?float $timeSlotHours,
        private readonly int $minSpacingSeconds,
        private readonly int $phashMinHamming,
        private readonly ?int $maxPerStaypoint,
        private readonly ?int $relaxedMaxPerStaypoint,
        private readonly float $qualityFloor,
        private readonly float $videoBonus,
        private readonly float $faceBonus,
        private readonly float $selfiePenalty,
        private readonly ?int $maxPerYear = null,
        private readonly ?int $maxPerBucket = null,
        private readonly ?float $videoHeavyBonus = null,
    ) {
        if ($targetTotal <= 0) {
            throw new InvalidArgumentException('targetTotal must be positive.');
        }

        if ($minimumTotal <= 0 || $minimumTotal > $targetTotal) {
            throw new InvalidArgumentException('minimumTotal must be positive and not exceed targetTotal.');
        }

        if ($minSpacingSeconds < 0) {
            throw new InvalidArgumentException('minSpacingSeconds must not be negative.');
        }

        if ($phashMinHamming < 0) {
            throw new InvalidArgumentException('phashMinHamming must not be negative.');
        }

        if ($qualityFloor < 0.0) {
            throw new InvalidArgumentException('qualityFloor must not be negative.');
        }

        if ($relaxedMaxPerStaypoint !== null && $relaxedMaxPerStaypoint < 0) {
            throw new InvalidArgumentException('relaxedMaxPerStaypoint must not be negative.');
        }
    }

    public function getProfileKey(): string
    {
        return $this->profileKey;
    }

    public function getTargetTotal(): int
    {
        return $this->targetTotal;
    }

    public function getMinimumTotal(): int
    {
        return $this->minimumTotal;
    }

    public function getMaxPerDay(): ?int
    {
        return $this->maxPerDay;
    }

    public function getTimeSlotHours(): ?float
    {
        return $this->timeSlotHours;
    }

    public function getMinSpacingSeconds(): int
    {
        return $this->minSpacingSeconds;
    }

    public function getPhashMinHamming(): int
    {
        return $this->phashMinHamming;
    }

    public function getMaxPerStaypoint(): ?int
    {
        return $this->maxPerStaypoint;
    }

    public function getRelaxedMaxPerStaypoint(): ?int
    {
        return $this->relaxedMaxPerStaypoint;
    }

    public function getQualityFloor(): float
    {
        return $this->qualityFloor;
    }

    public function getVideoBonus(): float
    {
        return $this->videoBonus;
    }

    public function getFaceBonus(): float
    {
        return $this->faceBonus;
    }

    public function getSelfiePenalty(): float
    {
        return $this->selfiePenalty;
    }

    public function getMaxPerYear(): ?int
    {
        return $this->maxPerYear;
    }

    public function getMaxPerBucket(): ?int
    {
        return $this->maxPerBucket;
    }

    public function getVideoHeavyBonus(): ?float
    {
        return $this->videoHeavyBonus;
    }

    public function withRelaxedSpacing(int $spacing): self
    {
        return new self(
            $this->profileKey,
            $this->targetTotal,
            $this->minimumTotal,
            $this->maxPerDay,
            $this->timeSlotHours,
            $spacing,
            $this->phashMinHamming,
            $this->maxPerStaypoint,
            $this->relaxedMaxPerStaypoint,
            $this->qualityFloor,
            $this->videoBonus,
            $this->faceBonus,
            $this->selfiePenalty,
            $this->maxPerYear,
            $this->maxPerBucket,
            $this->videoHeavyBonus,
        );
    }

    public function withRelaxedHamming(int $hamming): self
    {
        return new self(
            $this->profileKey,
            $this->targetTotal,
            $this->minimumTotal,
            $this->maxPerDay,
            $this->timeSlotHours,
            $this->minSpacingSeconds,
            $hamming,
            $this->maxPerStaypoint,
            $this->relaxedMaxPerStaypoint,
            $this->qualityFloor,
            $this->videoBonus,
            $this->faceBonus,
            $this->selfiePenalty,
            $this->maxPerYear,
            $this->maxPerBucket,
            $this->videoHeavyBonus,
        );
    }

    public function withMaxPerStaypoint(?int $staypointCap): self
    {
        return new self(
            $this->profileKey,
            $this->targetTotal,
            $this->minimumTotal,
            $this->maxPerDay,
            $this->timeSlotHours,
            $this->minSpacingSeconds,
            $this->phashMinHamming,
            $staypointCap,
            $this->relaxedMaxPerStaypoint,
            $this->qualityFloor,
            $this->videoBonus,
            $this->faceBonus,
            $this->selfiePenalty,
            $this->maxPerYear,
            $this->maxPerBucket,
            $this->videoHeavyBonus,
        );
    }

    public function withoutCaps(): self
    {
        return new self(
            $this->profileKey,
            $this->targetTotal,
            $this->minimumTotal,
            null,
            $this->timeSlotHours,
            $this->minSpacingSeconds,
            $this->phashMinHamming,
            null,
            null,
            $this->qualityFloor,
            $this->videoBonus,
            $this->faceBonus,
            $this->selfiePenalty,
            $this->maxPerYear,
            null,
            $this->videoHeavyBonus,
        );
    }
}
