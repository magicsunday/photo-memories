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
use function is_float;
use function is_int;

/**
 * Resolves selection policies based on algorithm/profile mappings.
 */
final class SelectionPolicyProvider
{
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

    public function forAlgorithm(string $algorithm): SelectionPolicy
    {
        $profileKey = $this->algorithmProfiles[$algorithm] ?? $this->defaultProfile;
        if ($profileKey === '' || !array_key_exists($profileKey, $this->profiles)) {
            throw new InvalidArgumentException('No selection policy configured for algorithm ' . $algorithm);
        }

        /** @var array<string, int|float|string|null> $config */
        $config = $this->profiles[$profileKey];

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
