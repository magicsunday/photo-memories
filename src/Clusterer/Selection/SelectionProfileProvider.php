<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Selection;

use function array_key_exists;
use function array_merge;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Provides curated selection option profiles per cluster algorithm.
 */
final class SelectionProfileProvider
{
    /** @var array<string, array<string, int|float>> */
    private array $profiles = [];

    /** @var array<string, string> */
    private array $algorithmProfiles = [];

    /** @var array<string, int|float> */
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
    ) {
        $this->profiles          = $this->sanitizeProfiles($profiles);
        $this->algorithmProfiles = $this->sanitizeAlgorithmProfiles($algorithmProfiles);
    }

    /**
     * Applies runtime overrides that affect every generated option set.
     *
     * @param array<string, int|float|string|null> $overrides
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
     * @param array<string, int|float|string|null> $overrides
     */
    public function createOptions(string $profileKey, array $overrides = []): VacationSelectionOptions
    {
        $baseOverrides = $this->profiles[$profileKey] ?? [];

        /** @var array<string, int|float> $merged */
        $merged = array_merge($baseOverrides, $this->runtimeOverrides, $this->sanitizeOverrides($overrides));

        $targetTotal = $this->intValue($merged, 'target_total', $this->defaultOptions->targetTotal);

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
            minimumTotal: $this->intValue($merged, 'minimum_total', $targetTotal),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return array<string, array<string, int|float>>
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
     * @param array<string, int|float|string|null> $values
     *
     * @return array<string, int|float>
     */
    private function sanitizeOverrides(array $values): array
    {
        $result = [];

        foreach (['target_total', 'max_per_day', 'time_slot_hours', 'min_spacing_seconds', 'phash_min_hamming', 'max_per_staypoint', 'minimum_total'] as $key) {
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

        foreach (['video_bonus', 'face_bonus', 'selfie_penalty', 'quality_floor'] as $key) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_numeric($value)) {
                $result[$key] = (float) $value;
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
}
