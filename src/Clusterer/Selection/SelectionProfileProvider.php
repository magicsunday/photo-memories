<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Selection;

use MagicSunday\Memories\Service\Metadata\Support\FaceDetectionAvailability;
use function array_key_exists;
use function array_merge;
use function filter_var;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function max;
use function round;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;

/**
 * Provides curated selection option profiles per cluster algorithm.
 */
final class SelectionProfileProvider
{
    /** @var array<string, array<string, int|float|bool>> */
    private array $profiles = [];

    /** @var array<string, string> */
    private array $algorithmProfiles = [];

    /** @var array<string, int|float|bool> */
    private array $runtimeOverrides = [];

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @param array<string, string>               $algorithmProfiles
     */
    public function __construct(
        private readonly VacationSelectionOptions $defaultOptions,
        private readonly string $defaultProfile = 'default',
        array $profiles = [],
        array $algorithmProfiles = [],
        private readonly ?FaceDetectionAvailability $faceDetectionAvailability = null,
    ) {
        $this->profiles          = $this->sanitizeProfiles($profiles);
        $this->algorithmProfiles = $this->sanitizeAlgorithmProfiles($algorithmProfiles);
    }

    /**
     * Applies runtime overrides that affect every generated option set.
     *
     * @param array<string, int|float|string|bool|null> $overrides
     */
    public function setRuntimeOverrides(array $overrides): void
    {
        $this->runtimeOverrides = $this->sanitizeOverrides($overrides);
    }

    public function determineProfileKey(string $algorithm, ?string $requestedProfile = null): string
    {
        if (is_string($requestedProfile) && $requestedProfile !== '') {
            return $requestedProfile;
        }

        $profile = $this->algorithmProfiles[$algorithm] ?? $this->defaultProfile;
        if (!is_string($profile) || $profile === '') {
            return $this->defaultProfile;
        }

        return $profile;
    }

    /**
     * @param array<string, int|float|string|bool|null> $overrides
     */
    public function createOptions(string $profileKey, array $overrides = []): VacationSelectionOptions
    {
        $baseOverrides = $this->profiles[$profileKey] ?? [];

        /** @var array<string, int|float> $merged */
        $merged = array_merge($baseOverrides, $this->runtimeOverrides, $this->sanitizeOverrides($overrides));

        $targetTotal   = $this->intValue($merged, 'target_total', $this->defaultOptions->targetTotal);
        $minimumTotal = $this->resolveMinimumTotal($merged, $targetTotal);

        $faceDetectionAvailable = $this->faceDetectionAvailability?->isAvailable() ?? true;
        if ($profileKey === 'vacation_weekend_transit' && !$faceDetectionAvailable) {
            $merged['face_bonus']  = 0.10;
            $merged['video_bonus'] = 0.25;
        }

        return new VacationSelectionOptions(
            targetTotal: $targetTotal,
            maxPerDay: $this->intValue($merged, 'max_per_day', $this->defaultOptions->maxPerDay),
            timeSlotHours: $this->intValue($merged, 'time_slot_hours', $this->defaultOptions->timeSlotHours),
            minSpacingSeconds: $this->intValue($merged, 'min_spacing_seconds', $this->defaultOptions->minSpacingSeconds),
            phashMinHamming: $this->intValue($merged, 'phash_min_hamming', $this->defaultOptions->phashMinHamming),
            maxPerStaypoint: $this->intValue($merged, 'max_per_staypoint', $this->defaultOptions->maxPerStaypoint),
            videoBonus: $this->floatValue($merged, 'video_bonus', $this->defaultOptions->videoBonus),
            faceBonus: $this->floatValue($merged, 'face_bonus', $this->defaultOptions->faceBonus),
            selfiePenalty: $this->floatValue($merged, 'selfie_penalty', $this->defaultOptions->selfiePenalty),
            qualityFloor: $this->floatValue($merged, 'quality_floor', $this->defaultOptions->qualityFloor),
            enablePeopleBalance: $this->boolValue($merged, 'enable_people_balance', $this->defaultOptions->enablePeopleBalance),
            peopleBalanceWeight: $this->floatValue($merged, 'people_balance_weight', $this->defaultOptions->peopleBalanceWeight),
            faceDetectionAvailable: $faceDetectionAvailable,
            repeatPenalty: $this->floatValue($merged, 'repeat_penalty', $this->defaultOptions->repeatPenalty),
            coreDayBonus: $this->intValue($merged, 'core_day_bonus', $this->defaultOptions->coreDayBonus),
            peripheralDayPenalty: $this->intValue($merged, 'peripheral_day_penalty', $this->defaultOptions->peripheralDayPenalty),
            phashPercentile: $this->floatValue($merged, 'phash_percentile', $this->defaultOptions->phashPercentile),
            spacingProgressFactor: $this->floatValue($merged, 'spacing_progress_factor', $this->defaultOptions->spacingProgressFactor),
            cohortRepeatPenalty: $this->floatValue($merged, 'cohort_repeat_penalty', $this->defaultOptions->cohortRepeatPenalty),
            minimumTotal: $minimumTotal,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return array<string, array<string, int|float|bool>>
     */
    private function sanitizeProfiles(array $profiles): array
    {
        $result = [];

        foreach ($profiles as $key => $values) {
            if (!is_string($key) || $key === '' || !is_array($values)) {
                continue;
            }

            $result[$key] = $this->sanitizeOverrides($values);
        }

        if (!array_key_exists($this->defaultProfile, $result)) {
            $result[$this->defaultProfile] = [];
        }

        return $result;
    }

    /**
     * @param array<string, string> $algorithmProfiles
     *
     * @return array<string, string>
     */
    private function sanitizeAlgorithmProfiles(array $algorithmProfiles): array
    {
        $result = [];

        foreach ($algorithmProfiles as $algorithm => $profile) {
            if (!is_string($algorithm) || $algorithm === '' || !is_string($profile) || $profile === '') {
                continue;
            }

            $result[$algorithm] = $profile;
        }

        return $result;
    }

    /**
     * @param array<string, int|float|string|bool|null> $values
     *
     * @return array<string, int|float|bool>
     */
    private function sanitizeOverrides(array $values): array
    {
        $result = [];

        foreach (['target_total', 'max_per_day', 'time_slot_hours', 'min_spacing_seconds', 'phash_min_hamming', 'max_per_staypoint', 'minimum_total', 'core_day_bonus', 'peripheral_day_penalty'] as $key) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_int($value)) {
                $result[$key] = $value;

                continue;
            }

            if (is_numeric($value)) {
                $result[$key] = (int) $value;
            }
        }

        foreach (['video_bonus', 'face_bonus', 'selfie_penalty', 'quality_floor', 'people_balance_weight', 'repeat_penalty', 'phash_percentile', 'spacing_progress_factor', 'cohort_repeat_penalty'] as $key) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_numeric($value)) {
                $result[$key] = (float) $value;
            }
        }

        foreach (['enable_people_balance'] as $key) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $result[$key] = $value;

                continue;
            }

            if (is_int($value)) {
                $result[$key] = $value !== 0;

                continue;
            }

            if (is_string($value)) {
                $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($filtered !== null) {
                    $result[$key] = $filtered;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, int|float> $values
     */
    private function intValue(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @param array<string, int|float> $values
     */
    private function floatValue(array $values, string $key, float $default): float
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        return (float) $value;
    }

    /**
     * @param array<string, int|float|bool> $values
     */
    private function boolValue(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                return $filtered;
            }
        }

        return $default;
    }

    /**
     * @param array<string, int|float|bool> $values
     */
    private function resolveMinimumTotal(array $values, int $targetTotal): int
    {
        if (array_key_exists('minimum_total', $values)) {
            return (int) $values['minimum_total'];
        }

        $computed = (int) round($targetTotal * 0.6);
        $computed = max(24, $computed);

        if ($computed > $targetTotal) {
            return $targetTotal;
        }

        return $computed;
    }
}
