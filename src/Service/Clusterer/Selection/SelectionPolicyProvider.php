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
use function explode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Resolves selection policies based on algorithm/profile mappings.
 */
final class SelectionPolicyProvider
{
    private const DEFAULT_STORYLINE = 'default';

    /**
     * @var array<string, int|float|null>
     */
    private array $runtimeOverrides = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $algorithmProfiles = [];

    /**
     * @var array<string, array{
     *     target_by_run_length: array<string, int>|null,
     *     minimum_by_run_length: array<string, int>|null,
     *     scalar_overrides: array<string, int|float|bool>,
     * }>
     */
    private array $profileConstraints = [];

    /**
     * @param array<string, array<string, int|float|string|null>> $profiles
     * @param array<string, string|array<string, string>>         $algorithmProfiles
     * @param array<string, array<string, mixed>>                 $profileConstraints
     */
    public function __construct(
        private readonly array $profiles,
        private readonly string $defaultProfile,
        array $algorithmProfiles = [],
        array $profileConstraints = [],
    ) {
        $this->algorithmProfiles   = $this->sanitizeAlgorithmProfiles($algorithmProfiles);
        $this->profileConstraints = $this->sanitizeProfileConstraints($profileConstraints);
    }

    /**
     * @param array<string, int|float|string|null> $overrides
     */
    public function setRuntimeOverrides(array $overrides): void
    {
        $this->runtimeOverrides = $this->sanitizeOverrides($overrides);
    }

    public function forAlgorithm(string $algorithm, ?string $storyline = null): SelectionPolicy
    {
        return $this->forAlgorithmWithRunLength($algorithm, $storyline, null);
    }

    public function forAlgorithmWithRunLength(
        string $algorithm,
        ?string $storyline = null,
        ?int $runLengthDays = null,
    ): SelectionPolicy {
        $profileKey = $this->resolveProfileKey($algorithm, $storyline);

        /** @var array<string, int|float|string|null> $config */
        $config = $this->profiles[$profileKey];

        $constraintResolution = $this->resolveConstraintOverrides($profileKey, $runLengthDays);
        if ($constraintResolution['values'] !== []) {
            $config = array_merge($config, $constraintResolution['values']);
        }

        if ($this->runtimeOverrides !== []) {
            $config = array_merge($config, $this->runtimeOverrides);
        }

        $config   = $this->finalizeConfig($config);
        $metadata = $constraintResolution['metadata'];

        return $this->createPolicy($profileKey, $config, $metadata);
    }

    /**
     * @param array<string, int|float|string|null> $config
     * @param array<string, mixed>                 $metadata
     */
    private function createPolicy(string $profileKey, array $config, array $metadata): SelectionPolicy
    {
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
            mmrLambda: $this->floatOptional($config, 'mmr_lambda', 0.75),
            mmrSimilarityFloor: $this->floatOptional($config, 'mmr_similarity_floor', 0.35),
            mmrSimilarityCap: $this->floatOptional($config, 'mmr_similarity_cap', 0.9),
            mmrMaxConsideration: $this->intOptional($config, 'mmr_max_results', 120),
            maxPerYear: $this->intOrNull($config, 'max_per_year'),
            maxPerBucket: $this->intOrNull($config, 'max_per_bucket'),
            videoHeavyBonus: $this->floatOrNull($config, 'video_heavy_bonus'),
            sceneBucketWeights: $this->floatMapOrNull($config, 'scene_bucket_weights'),
            coreDayBonus: $this->intOptional($config, 'core_day_bonus', 1),
            peripheralDayPenalty: $this->intOptional($config, 'peripheral_day_penalty', 1),
            phashPercentile: $this->floatOptional($config, 'phash_percentile', 0.35),
            spacingProgressFactor: $this->floatOptional($config, 'spacing_progress_factor', 0.5),
            cohortPenalty: $this->floatOptional($config, 'cohort_repeat_penalty', 0.05),
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, int|float|string|null> $config
     *
     * @return array<string, int|float|string|null>
     */
    private function finalizeConfig(array $config): array
    {
        $targetTotal  = $config['target_total'] ?? null;
        $minimumTotal = $config['minimum_total'] ?? null;

        if (is_int($targetTotal) && is_int($minimumTotal) && $minimumTotal > $targetTotal) {
            $config['minimum_total'] = $targetTotal;
        }

        return $config;
    }

    /**
     * @return array{values: array<string, int|float|bool>, metadata: array<string, mixed>}
     */
    private function resolveConstraintOverrides(string $profileKey, ?int $runLengthDays): array
    {
        if (!isset($this->profileConstraints[$profileKey])) {
            $metadata = $runLengthDays !== null ? ['run_length_days' => $runLengthDays] : [];

            return ['values' => [], 'metadata' => $metadata];
        }

        $definition = $this->profileConstraints[$profileKey];

        $overrides = $definition['scalar_overrides'];

        $targetOverride  = null;
        $minimumOverride = null;

        if ($runLengthDays !== null) {
            $targetOverride  = $this->resolveRunLengthValue($definition['target_by_run_length'], $runLengthDays);
            $minimumOverride = $this->resolveRunLengthValue($definition['minimum_by_run_length'], $runLengthDays);

            if ($targetOverride !== null) {
                $overrides['target_total'] = $targetOverride;
            }

            if ($minimumOverride !== null) {
                $overrides['minimum_total'] = $minimumOverride;
            }
        }

        $metadata = [];
        if ($runLengthDays !== null) {
            $metadata['run_length_days'] = $runLengthDays;
        }

        if ($overrides !== []) {
            $metadata['constraint_overrides'] = $overrides;
        }

        return ['values' => $overrides, 'metadata' => $metadata];
    }

    /**
     * @param array<string, int>|null $runLengthConfig
     */
    private function resolveRunLengthValue(?array $runLengthConfig, int $runLengthDays): ?int
    {
        if ($runLengthConfig === null) {
            return null;
        }

        $shortMaxDays   = $runLengthConfig['short_run_max_days'] ?? null;
        $shortTarget    = $runLengthConfig['short_run_target_total'] ?? $runLengthConfig['short_run_minimum_total'] ?? null;
        $mediumMaxDays  = $runLengthConfig['medium_run_max_days'] ?? null;
        $mediumTarget   = $runLengthConfig['medium_run_target_total'] ?? $runLengthConfig['medium_run_minimum_total'] ?? null;
        $longTarget     = $runLengthConfig['long_run_target_total'] ?? $runLengthConfig['long_run_minimum_total'] ?? null;

        if (is_int($shortMaxDays) && $runLengthDays <= $shortMaxDays && is_int($shortTarget)) {
            return $shortTarget;
        }

        if (is_int($mediumMaxDays) && $runLengthDays <= $mediumMaxDays && is_int($mediumTarget)) {
            return $mediumTarget;
        }

        return is_int($longTarget) ? $longTarget : null;
    }

    /**
     * @param array<string, string|array<string, string>> $algorithmProfiles
     *
     * @return array<string, array<string, string>>
     */
    private function sanitizeAlgorithmProfiles(array $algorithmProfiles): array
    {
        $result = [];

        foreach ($algorithmProfiles as $algorithm => $mapping) {
            if (!is_string($algorithm) || $algorithm === '') {
                continue;
            }

            if (is_string($mapping)) {
                if ($mapping === '') {
                    continue;
                }

                $result[$algorithm] = [self::DEFAULT_STORYLINE => $mapping];

                continue;
            }

            if (!is_array($mapping)) {
                continue;
            }

            $sanitised = [];
            foreach ($mapping as $storyline => $profileKey) {
                if (!is_string($storyline) || $storyline === '' || !is_string($profileKey) || $profileKey === '') {
                    continue;
                }

                $sanitised[$storyline] = $profileKey;
            }

            if (isset($mapping[self::DEFAULT_STORYLINE]) && is_string($mapping[self::DEFAULT_STORYLINE])) {
                $defaultProfile = $mapping[self::DEFAULT_STORYLINE];
                if ($defaultProfile !== '') {
                    $sanitised[self::DEFAULT_STORYLINE] = $defaultProfile;
                }
            }

            if ($sanitised === []) {
                continue;
            }

            if (!isset($sanitised[self::DEFAULT_STORYLINE])) {
                $first = reset($sanitised);
                if (is_string($first) && $first !== '') {
                    $sanitised[self::DEFAULT_STORYLINE] = $first;
                }
            }

            $result[$algorithm] = $sanitised;
        }

        return $result;
    }

    private function resolveProfileKey(string $algorithm, ?string $storyline): string
    {
        if (!array_key_exists($algorithm, $this->algorithmProfiles)) {
            $this->guardProfileExists($this->defaultProfile, $algorithm);

            return $this->defaultProfile;
        }

        $mapping     = $this->algorithmProfiles[$algorithm];
        $candidates  = $this->storylineCandidates($storyline);
        $candidates[] = self::DEFAULT_STORYLINE;

        foreach ($candidates as $candidate) {
            if (isset($mapping[$candidate])) {
                $profileKey = $mapping[$candidate];
                $this->guardProfileExists($profileKey, $algorithm);

                return $profileKey;
            }
        }

        $fallback = reset($mapping);
        if (is_string($fallback) && $fallback !== '') {
            $this->guardProfileExists($fallback, $algorithm);

            return $fallback;
        }

        $this->guardProfileExists($this->defaultProfile, $algorithm);

        return $this->defaultProfile;
    }

    private function guardProfileExists(string $profileKey, string $algorithm): void
    {
        if ($profileKey === '' || !array_key_exists($profileKey, $this->profiles)) {
            throw new InvalidArgumentException('No selection policy configured for algorithm ' . $algorithm);
        }
    }

    /**
     * @return list<string>
     */
    private function storylineCandidates(?string $storyline): array
    {
        if (!is_string($storyline)) {
            return [];
        }

        $trimmed = trim($storyline);
        if ($trimmed === '') {
            return [];
        }

        $candidates = [$trimmed];
        if (str_contains($trimmed, '.')) {
            $parts = explode('.', $trimmed);
            $suffix = end($parts);
            if (is_string($suffix) && $suffix !== '' && $suffix !== $trimmed) {
                $candidates[] = $suffix;
            }
        }

        return $candidates;
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
        $this->assignFloatOverride($sanitised, $overrides, 'mmr_lambda');
        $this->assignFloatOverride($sanitised, $overrides, 'mmr_similarity_floor');
        $this->assignFloatOverride($sanitised, $overrides, 'mmr_similarity_cap');
        $this->assignIntOverride($sanitised, $overrides, 'mmr_max_results');
        $this->assignIntOrNullOverride($sanitised, $overrides, 'max_per_year');
        $this->assignIntOrNullOverride($sanitised, $overrides, 'max_per_bucket');
        $this->assignFloatOrNullOverride($sanitised, $overrides, 'video_heavy_bonus');
        $this->assignIntOverride($sanitised, $overrides, 'core_day_bonus');
        $this->assignIntOverride($sanitised, $overrides, 'peripheral_day_penalty');
        $this->assignFloatOverride($sanitised, $overrides, 'phash_percentile');
        $this->assignFloatOverride($sanitised, $overrides, 'spacing_progress_factor');
        $this->assignFloatOverride($sanitised, $overrides, 'cohort_repeat_penalty');

        return $sanitised;
    }

    /**
     * @param array<string, array<string, mixed>> $profileConstraints
     *
     * @return array<string, array{
     *     target_by_run_length: array<string, int>|null,
     *     minimum_by_run_length: array<string, int>|null,
     *     scalar_overrides: array<string, int|float|bool>,
     * }>
     */
    private function sanitizeProfileConstraints(array $profileConstraints): array
    {
        $result = [];

        foreach ($profileConstraints as $profileKey => $definition) {
            if (!is_string($profileKey) || $profileKey === '' || !is_array($definition)) {
                continue;
            }

            $targetByRunLength  = $this->sanitizeRunLengthConfig($definition['target_total_by_run_length'] ?? null);
            $minimumByRunLength = $this->sanitizeRunLengthConfig($definition['minimum_total_by_run_length'] ?? null);
            $scalarOverrides    = $this->sanitizeConstraintScalarOverrides($definition);

            if ($targetByRunLength === null && $minimumByRunLength === null && $scalarOverrides === []) {
                continue;
            }

            $result[$profileKey] = [
                'target_by_run_length' => $targetByRunLength,
                'minimum_by_run_length' => $minimumByRunLength,
                'scalar_overrides' => $scalarOverrides,
            ];
        }

        return $result;
    }

    /**
     * @param mixed $config
     *
     * @return array<string, int>|null
     */
    private function sanitizeRunLengthConfig(mixed $config): ?array
    {
        if (!is_array($config)) {
            return null;
        }

        $sanitised = [];

        foreach ([
            'short_run_max_days',
            'short_run_target_total',
            'short_run_minimum_total',
            'medium_run_max_days',
            'medium_run_target_total',
            'medium_run_minimum_total',
            'long_run_target_total',
            'long_run_minimum_total',
        ] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];
            if (is_int($value)) {
                $sanitised[$key] = $value;

                continue;
            }

            if (is_float($value) || (is_string($value) && is_numeric($value))) {
                $sanitised[$key] = (int) $value;
            }
        }

        return $sanitised === [] ? null : $sanitised;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, int|float|bool>
     */
    private function sanitizeConstraintScalarOverrides(array $definition): array
    {
        $overrides = [];

        foreach (['enable_people_balance'] as $boolKey) {
            if (!array_key_exists($boolKey, $definition)) {
                continue;
            }

            $value = $definition[$boolKey];
            if (is_bool($value)) {
                $overrides[$boolKey] = $value;

                continue;
            }

            if (is_string($value)) {
                $normalised = strtolower(trim($value));
                if ($normalised === 'true' || $normalised === '1') {
                    $overrides[$boolKey] = true;

                    continue;
                }

                if ($normalised === 'false' || $normalised === '0') {
                    $overrides[$boolKey] = false;
                }
            }
        }

        foreach (['people_balance_weight', 'repeat_penalty', 'mmr_lambda', 'mmr_similarity_floor', 'mmr_similarity_cap'] as $floatKey) {
            if (!array_key_exists($floatKey, $definition)) {
                continue;
            }

            $value = $definition[$floatKey];
            if (is_float($value) || is_int($value)) {
                $overrides[$floatKey] = (float) $value;

                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $overrides[$floatKey] = (float) $value;
            }
        }

        foreach (['target_total', 'minimum_total', 'mmr_max_results'] as $intKey) {
            if (!array_key_exists($intKey, $definition)) {
                continue;
            }

            $value = $definition[$intKey];
            if (is_int($value)) {
                $overrides[$intKey] = $value;

                continue;
            }

            if (is_float($value) || (is_string($value) && is_numeric($value))) {
                $overrides[$intKey] = (int) $value;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, int|float|string|null> $config
     *
     * @return array<string, float>|null
     */
    private function floatMapOrNull(array $config, string $key): ?array
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        $weights = [];
        foreach ($value as $bucket => $weight) {
            if (!is_string($bucket) || $bucket === '') {
                continue;
            }

            if (is_float($weight) || is_int($weight) || (is_string($weight) && is_numeric($weight))) {
                $weights[$bucket] = (float) $weight;
            }
        }

        return $weights;
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

    private function intOptional(array $config, string $key, int $default): int
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    private function floatOptional(array $config, string $key, float $default): float
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
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
