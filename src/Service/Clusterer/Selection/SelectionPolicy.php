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

use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;

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
 * @param float         $mmrLambda          weighting factor used for maximal marginal relevance
 * @param float         $mmrSimilarityFloor lower bound for applying similarity penalties
 * @param float         $mmrSimilarityCap   upper bound for capping similarity penalties
 * @param int           $mmrMaxConsideration maximum number of candidates considered during MMR re-ranking
     * @param int|null      $maxPerYear         per-year cap for over-years memories
     * @param int|null      $maxPerBucket       optional generic bucket cap
     * @param float|null    $videoHeavyBonus    optional bonus applied when cluster is video heavy
     * @param array<string, float>|null $sceneBucketWeights optional target share weights per motif bucket
     * @param int           $coreDayBonus       additional quota points assigned to core days
     * @param int           $peripheralDayPenalty quota reduction applied to peripheral days
     * @param float         $phashPercentile    percentile used for adaptive perceptual hash thresholding
 * @param float         $spacingProgressFactor scaling factor for progressive spacing relaxations
 * @param float         $cohortPenalty      penalty applied for repeating person signatures
 * @param int|null      $peripheralDayMaxTotal optional cap for the sum of periphery day quotas
 * @param int|null      $peripheralDayHardCap  optional hard cap applied to individual periphery days
 * @param array<string, int> $dayQuotas     runtime day quota overrides keyed by ISO date
 * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $dayContext runtime day classification metadata
 * @param array<string, mixed> $metadata    supplementary metadata describing policy derivation
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
        private readonly float $mmrLambda = 0.75,
        private readonly float $mmrSimilarityFloor = 0.35,
        private readonly float $mmrSimilarityCap = 0.9,
        private readonly int $mmrMaxConsideration = 120,
        private readonly ?int $maxPerYear = null,
        private readonly ?int $maxPerBucket = null,
        private readonly ?float $videoHeavyBonus = null,
        private readonly ?array $sceneBucketWeights = null,
        private readonly int $coreDayBonus = 1,
        private readonly int $peripheralDayPenalty = 1,
        private readonly float $phashPercentile = 0.35,
        private readonly float $spacingProgressFactor = 0.5,
        private readonly float $cohortPenalty = 0.05,
        private readonly ?int $peripheralDayMaxTotal = null,
        private readonly ?int $peripheralDayHardCap = null,
        private readonly array $dayQuotas = [],
        private readonly array $dayContext = [],
        private readonly array $metadata = [],
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

        if ($mmrLambda < 0.0 || $mmrLambda > 1.0) {
            throw new InvalidArgumentException('mmrLambda must be within [0,1].');
        }

        if ($mmrSimilarityFloor < 0.0 || $mmrSimilarityFloor > 1.0) {
            throw new InvalidArgumentException('mmrSimilarityFloor must be within [0,1].');
        }

        if ($mmrSimilarityCap < 0.0 || $mmrSimilarityCap > 1.0) {
            throw new InvalidArgumentException('mmrSimilarityCap must be within [0,1].');
        }

        if ($mmrSimilarityFloor > $mmrSimilarityCap) {
            throw new InvalidArgumentException('mmrSimilarityFloor must not exceed mmrSimilarityCap.');
        }

        if ($mmrMaxConsideration <= 0) {
            throw new InvalidArgumentException('mmrMaxConsideration must be positive.');
        }

        if ($sceneBucketWeights !== null) {
            foreach ($sceneBucketWeights as $bucket => $weight) {
                if (!is_string($bucket) || $bucket === '') {
                    throw new InvalidArgumentException('Scene bucket weights must use non-empty string keys.');
                }

                if (!is_float($weight) && !is_int($weight)) {
                    throw new InvalidArgumentException('Scene bucket weights must be numeric.');
                }

                if ((float) $weight < 0.0) {
                    throw new InvalidArgumentException('Scene bucket weights must not be negative.');
                }
            }
        }

        if ($coreDayBonus < 0) {
            throw new InvalidArgumentException('coreDayBonus must not be negative.');
        }

        if ($peripheralDayPenalty < 0) {
            throw new InvalidArgumentException('peripheralDayPenalty must not be negative.');
        }

        if ($phashPercentile < 0.0 || $phashPercentile > 1.0) {
            throw new InvalidArgumentException('phashPercentile must be within [0,1].');
        }

        if ($spacingProgressFactor < 0.0 || $spacingProgressFactor > 1.0) {
            throw new InvalidArgumentException('spacingProgressFactor must be within [0,1].');
        }

        if ($cohortPenalty < 0.0) {
            throw new InvalidArgumentException('cohortPenalty must not be negative.');
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

    public function getMmrLambda(): float
    {
        return $this->mmrLambda;
    }

    public function getMmrSimilarityFloor(): float
    {
        return $this->mmrSimilarityFloor;
    }

    public function getMmrSimilarityCap(): float
    {
        return $this->mmrSimilarityCap;
    }

    public function getMmrMaxConsideration(): int
    {
        return $this->mmrMaxConsideration;
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

    /**
     * @return array<string, float>
     */
    public function getSceneBucketWeights(): array
    {
        if ($this->sceneBucketWeights === null) {
            return [];
        }

        $weights = [];
        foreach ($this->sceneBucketWeights as $bucket => $weight) {
            $weights[$bucket] = (float) $weight;
        }

        return $weights;
    }

    public function getCoreDayBonus(): int
    {
        return $this->coreDayBonus;
    }

    public function getPeripheralDayPenalty(): int
    {
        return $this->peripheralDayPenalty;
    }

    public function getPhashPercentile(): float
    {
        return $this->phashPercentile;
    }

    public function getSpacingProgressFactor(): float
    {
        return $this->spacingProgressFactor;
    }

    public function getCohortPenalty(): float
    {
        return $this->cohortPenalty;
    }

    public function getPeripheralDayMaxTotal(): ?int
    {
        return $this->peripheralDayMaxTotal;
    }

    public function getPeripheralDayHardCap(): ?int
    {
        return $this->peripheralDayHardCap;
    }

    /**
     * @return array<string, int>
     */
    public function getDayQuotas(): array
    {
        return $this->dayQuotas;
    }

    /**
     * @return array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}>
     */
    public function getDayContext(): array
    {
        return $this->dayContext;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function withRelaxedSpacing(int $spacing): self
    {
        return new self(
            profileKey: $this->profileKey,
            targetTotal: $this->targetTotal,
            minimumTotal: $this->minimumTotal,
            maxPerDay: $this->maxPerDay,
            timeSlotHours: $this->timeSlotHours,
            minSpacingSeconds: $spacing,
            phashMinHamming: $this->phashMinHamming,
            maxPerStaypoint: $this->maxPerStaypoint,
            relaxedMaxPerStaypoint: $this->relaxedMaxPerStaypoint,
            qualityFloor: $this->qualityFloor,
            videoBonus: $this->videoBonus,
            faceBonus: $this->faceBonus,
            selfiePenalty: $this->selfiePenalty,
            mmrLambda: $this->mmrLambda,
            mmrSimilarityFloor: $this->mmrSimilarityFloor,
            mmrSimilarityCap: $this->mmrSimilarityCap,
            mmrMaxConsideration: $this->mmrMaxConsideration,
            maxPerYear: $this->maxPerYear,
            maxPerBucket: $this->maxPerBucket,
            videoHeavyBonus: $this->videoHeavyBonus,
            sceneBucketWeights: $this->sceneBucketWeights,
            coreDayBonus: $this->coreDayBonus,
            peripheralDayPenalty: $this->peripheralDayPenalty,
            phashPercentile: $this->phashPercentile,
            spacingProgressFactor: $this->spacingProgressFactor,
            cohortPenalty: $this->cohortPenalty,
            peripheralDayMaxTotal: $this->peripheralDayMaxTotal,
            peripheralDayHardCap: $this->peripheralDayHardCap,
            dayQuotas: $this->dayQuotas,
            dayContext: $this->dayContext,
            metadata: $this->metadata,
        );
    }

    public function withRelaxedHamming(int $hamming): self
    {
        return new self(
            profileKey: $this->profileKey,
            targetTotal: $this->targetTotal,
            minimumTotal: $this->minimumTotal,
            maxPerDay: $this->maxPerDay,
            timeSlotHours: $this->timeSlotHours,
            minSpacingSeconds: $this->minSpacingSeconds,
            phashMinHamming: $hamming,
            maxPerStaypoint: $this->maxPerStaypoint,
            relaxedMaxPerStaypoint: $this->relaxedMaxPerStaypoint,
            qualityFloor: $this->qualityFloor,
            videoBonus: $this->videoBonus,
            faceBonus: $this->faceBonus,
            selfiePenalty: $this->selfiePenalty,
            mmrLambda: $this->mmrLambda,
            mmrSimilarityFloor: $this->mmrSimilarityFloor,
            mmrSimilarityCap: $this->mmrSimilarityCap,
            mmrMaxConsideration: $this->mmrMaxConsideration,
            maxPerYear: $this->maxPerYear,
            maxPerBucket: $this->maxPerBucket,
            videoHeavyBonus: $this->videoHeavyBonus,
            sceneBucketWeights: $this->sceneBucketWeights,
            coreDayBonus: $this->coreDayBonus,
            peripheralDayPenalty: $this->peripheralDayPenalty,
            phashPercentile: $this->phashPercentile,
            spacingProgressFactor: $this->spacingProgressFactor,
            cohortPenalty: $this->cohortPenalty,
            peripheralDayMaxTotal: $this->peripheralDayMaxTotal,
            peripheralDayHardCap: $this->peripheralDayHardCap,
            dayQuotas: $this->dayQuotas,
            dayContext: $this->dayContext,
            metadata: $this->metadata,
        );
    }

    public function withMaxPerStaypoint(?int $staypointCap): self
    {
        return new self(
            profileKey: $this->profileKey,
            targetTotal: $this->targetTotal,
            minimumTotal: $this->minimumTotal,
            maxPerDay: $this->maxPerDay,
            timeSlotHours: $this->timeSlotHours,
            minSpacingSeconds: $this->minSpacingSeconds,
            phashMinHamming: $this->phashMinHamming,
            maxPerStaypoint: $staypointCap,
            relaxedMaxPerStaypoint: $this->relaxedMaxPerStaypoint,
            qualityFloor: $this->qualityFloor,
            videoBonus: $this->videoBonus,
            faceBonus: $this->faceBonus,
            selfiePenalty: $this->selfiePenalty,
            mmrLambda: $this->mmrLambda,
            mmrSimilarityFloor: $this->mmrSimilarityFloor,
            mmrSimilarityCap: $this->mmrSimilarityCap,
            mmrMaxConsideration: $this->mmrMaxConsideration,
            maxPerYear: $this->maxPerYear,
            maxPerBucket: $this->maxPerBucket,
            videoHeavyBonus: $this->videoHeavyBonus,
            sceneBucketWeights: $this->sceneBucketWeights,
            coreDayBonus: $this->coreDayBonus,
            peripheralDayPenalty: $this->peripheralDayPenalty,
            phashPercentile: $this->phashPercentile,
            spacingProgressFactor: $this->spacingProgressFactor,
            cohortPenalty: $this->cohortPenalty,
            peripheralDayMaxTotal: $this->peripheralDayMaxTotal,
            peripheralDayHardCap: $this->peripheralDayHardCap,
            dayQuotas: $this->dayQuotas,
            dayContext: $this->dayContext,
            metadata: $this->metadata,
        );
    }

    public function withoutCaps(): self
    {
        return new self(
            profileKey: $this->profileKey,
            targetTotal: $this->targetTotal,
            minimumTotal: $this->minimumTotal,
            maxPerDay: null,
            timeSlotHours: $this->timeSlotHours,
            minSpacingSeconds: $this->minSpacingSeconds,
            phashMinHamming: $this->phashMinHamming,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: $this->qualityFloor,
            videoBonus: $this->videoBonus,
            faceBonus: $this->faceBonus,
            selfiePenalty: $this->selfiePenalty,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: $this->videoHeavyBonus,
            sceneBucketWeights: $this->sceneBucketWeights,
            coreDayBonus: $this->coreDayBonus,
            peripheralDayPenalty: $this->peripheralDayPenalty,
            phashPercentile: $this->phashPercentile,
            spacingProgressFactor: $this->spacingProgressFactor,
            cohortPenalty: $this->cohortPenalty,
            peripheralDayMaxTotal: $this->peripheralDayMaxTotal,
            peripheralDayHardCap: $this->peripheralDayHardCap,
            dayQuotas: $this->dayQuotas,
            dayContext: $this->dayContext,
            metadata: $this->metadata,
        );
    }

    /**
     * @param array<string, int> $dayQuotas
     * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $dayContext
     */
    public function withDayContext(
        array $dayQuotas,
        array $dayContext,
        ?int $peripheralDayMaxTotal = null,
        ?int $peripheralDayHardCap = null,
    ): self
    {
        return new self(
            profileKey: $this->profileKey,
            targetTotal: $this->targetTotal,
            minimumTotal: $this->minimumTotal,
            maxPerDay: $this->maxPerDay,
            timeSlotHours: $this->timeSlotHours,
            minSpacingSeconds: $this->minSpacingSeconds,
            phashMinHamming: $this->phashMinHamming,
            maxPerStaypoint: $this->maxPerStaypoint,
            relaxedMaxPerStaypoint: $this->relaxedMaxPerStaypoint,
            qualityFloor: $this->qualityFloor,
            videoBonus: $this->videoBonus,
            faceBonus: $this->faceBonus,
            selfiePenalty: $this->selfiePenalty,
            mmrLambda: $this->mmrLambda,
            mmrSimilarityFloor: $this->mmrSimilarityFloor,
            mmrSimilarityCap: $this->mmrSimilarityCap,
            mmrMaxConsideration: $this->mmrMaxConsideration,
            maxPerYear: $this->maxPerYear,
            maxPerBucket: $this->maxPerBucket,
            videoHeavyBonus: $this->videoHeavyBonus,
            sceneBucketWeights: $this->sceneBucketWeights,
            coreDayBonus: $this->coreDayBonus,
            peripheralDayPenalty: $this->peripheralDayPenalty,
            phashPercentile: $this->phashPercentile,
            spacingProgressFactor: $this->spacingProgressFactor,
            cohortPenalty: $this->cohortPenalty,
            peripheralDayMaxTotal: $peripheralDayMaxTotal ?? $this->peripheralDayMaxTotal,
            peripheralDayHardCap: $peripheralDayHardCap ?? $this->peripheralDayHardCap,
            dayQuotas: $dayQuotas,
            dayContext: $dayContext,
            metadata: $this->metadata,
        );
    }
}
