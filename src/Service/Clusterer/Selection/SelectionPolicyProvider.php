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

use function array_key_exists;
use function array_merge;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Resolves selection policies based on algorithm/profile mappings.
 */
final class SelectionPolicyProvider
{
    /**
     * @var array<string, int|float|null>
     */
    private array $runtimeOverrides = [];

    /**
     * @param array<string, array<string, int|float|string|null>> $profiles
     * @param array<string, string>                               $algorithmProfiles
     */
    public function __construct(
        private readonly array $profiles,
        private readonly array $algorithmProfiles,
        private readonly string $defaultProfile,
    ) {
    }

    /**
     * @param array<string, int|float|string|null> $overrides
     */
    public function setRuntimeOverrides(array $overrides): void
    {
        $this->runtimeOverrides = $this->sanitizeOverrides($overrides);
    }

    public function forAlgorithm(string $algorithm): SelectionPolicy
    {
        $profileKey = $this->algorithmProfiles[$algorithm] ?? $this->defaultProfile;
        if ($profileKey === '' || !array_key_exists($profileKey, $this->profiles)) {
            throw new InvalidArgumentException('No selection policy configured for algorithm ' . $algorithm);
        }

        /** @var array<string, int|float|string|null> $config */
        $config = array_merge($this->profiles[$profileKey], $this->runtimeOverrides);

        return new SelectionPolicy(
            profileKey: $profileKey,
            targetTotal: $this->intValue($config, 'target_total'),
            minimumTotal: $this->intValue($config, 'minimum_total'),
            maxPerDay: $this->intOrNull($config, 'max_per_day'),
            timeSlotHours: $this->floatOrNull($config, 'time_slot_hours'),
            minSpacingSeconds: $this->intValue($config, 'min_spacing_seconds'),
            phashMinHamming: $this->intValue($config, 'phash_min_hamming'),
            maxPerStaypoint: $this->intOrNull($config, 'max_per_staypoint'),
            relaxedMaxPerStaypoint: $this->intOrNull($config, 'max_per_staypoint_relaxed'),
            qualityFloor: $this->floatValue($config, 'quality_floor'),
            videoBonus: $this->floatValue($config, 'video_bonus'),
            faceBonus: $this->floatValue($config, 'face_bonus'),
            selfiePenalty: $this->floatValue($config, 'selfie_penalty'),
            maxPerYear: $this->intOrNull($config, 'max_per_year'),
            maxPerBucket: $this->intOrNull($config, 'max_per_bucket'),
            videoHeavyBonus: $this->floatOrNull($config, 'video_heavy_bonus'),
        );
    }

    /**
     * @param array<string, int|float|string|null> $overrides
     *
     * @return array<string, int|float|null>
     */
    private function sanitizeOverrides(array $overrides): array
    {
        $sanitised = [];

        $this->assignIntOverride($sanitised, $overrides, 'target_total');
        $this->assignIntOverride($sanitised, $overrides, 'minimum_total');
        $this->assignIntOrNullOverride($sanitised, $overrides, 'max_per_day');
        $this->assignFloatOrNullOverride($sanitised, $overrides, 'time_slot_hours');
        $this->assignIntOverride($sanitised, $overrides, 'min_spacing_seconds');
        $this->assignIntOverride($sanitised, $overrides, 'phash_min_hamming');
        $this->assignIntOrNullOverride($sanitised, $overrides, 'max_per_staypoint');
        $this->assignIntOrNullOverride($sanitised, $overrides, 'max_per_staypoint_relaxed');
        $this->assignFloatOverride($sanitised, $overrides, 'quality_floor');
        $this->assignFloatOverride($sanitised, $overrides, 'video_bonus');
        $this->assignFloatOverride($sanitised, $overrides, 'face_bonus');
        $this->assignFloatOverride($sanitised, $overrides, 'selfie_penalty');
        $this->assignIntOrNullOverride($sanitised, $overrides, 'max_per_year');
        $this->assignIntOrNullOverride($sanitised, $overrides, 'max_per_bucket');
        $this->assignFloatOrNullOverride($sanitised, $overrides, 'video_heavy_bonus');

        return $sanitised;
    }

    /**
     * @param array<string, int|float|null>              $target
     * @param array<string, int|float|string|null>       $source
     */
    private function assignIntOverride(array &$target, array $source, string $key): void
    {
        if (!array_key_exists($key, $source)) {
            return;
        }

        $value = $source[$key];
        if ($value === null) {
            return;
        }

        if (is_int($value)) {
            $target[$key] = $value;

            return;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            $target[$key] = (int) $value;
        }
    }

    /**
     * @param array<string, int|float|null>              $target
     * @param array<string, int|float|string|null>       $source
     */
    private function assignIntOrNullOverride(array &$target, array $source, string $key): void
    {
        if (!array_key_exists($key, $source)) {
            return;
        }

        $value = $source[$key];
        if ($value === null) {
            $target[$key] = null;

            return;
        }

        if (is_int($value)) {
            $target[$key] = $value;

            return;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            $target[$key] = (int) $value;
        }
    }

    /**
     * @param array<string, int|float|null>              $target
     * @param array<string, int|float|string|null>       $source
     */
    private function assignFloatOverride(array &$target, array $source, string $key): void
    {
        if (!array_key_exists($key, $source)) {
            return;
        }

        $value = $source[$key];
        if ($value === null) {
            return;
        }

        if (is_float($value) || is_int($value)) {
            $target[$key] = (float) $value;

            return;
        }

        if (is_string($value) && is_numeric($value)) {
            $target[$key] = (float) $value;
        }
    }

    /**
     * @param array<string, int|float|null>              $target
     * @param array<string, int|float|string|null>       $source
     */
    private function assignFloatOrNullOverride(array &$target, array $source, string $key): void
    {
        if (!array_key_exists($key, $source)) {
            return;
        }

        $value = $source[$key];
        if ($value === null) {
            $target[$key] = null;

            return;
        }

        if (is_float($value) || is_int($value)) {
            $target[$key] = (float) $value;

            return;
        }

        if (is_string($value) && is_numeric($value)) {
            $target[$key] = (float) $value;
        }
    }

    /**
     * @param array<string, int|float|string|null> $config
     */
    private function intValue(array $config, string $key): int
    {
        $value = $config[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('Configuration value ' . $key . ' must be int.');
        }

        return $value;
    }

    /**
     * @param array<string, int|float|string|null> $config
     */
    private function intOrNull(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
            throw new InvalidArgumentException('Configuration value ' . $key . ' must be int or null.');
        }

        return $value;
    }

    /**
     * @param array<string, int|float|string|null> $config
     */
    private function floatValue(array $config, string $key): float
    {
        $value = $config[$key] ?? null;
        if (!is_float($value) && !is_int($value)) {
            throw new InvalidArgumentException('Configuration value ' . $key . ' must be numeric.');
        }

        return (float) $value;
    }

    /**
     * @param array<string, int|float|string|null> $config
     */
    private function floatOrNull(array $config, string $key): ?float
    {
        $value = $config[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_float($value) && !is_int($value)) {
            throw new InvalidArgumentException('Configuration value ' . $key . ' must be numeric or null.');
        }

        return (float) $value;
    }
}
