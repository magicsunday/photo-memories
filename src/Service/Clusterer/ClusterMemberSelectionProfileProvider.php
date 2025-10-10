<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_merge;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Resolves the active selection profile and options for a cluster draft.
 */
final class ClusterMemberSelectionProfileProvider
{
    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    public function __construct(
        private readonly VacationSelectionOptions $defaultOptions,
        #[Autowire('%memories.cluster.selection.default_profile%')]
        private readonly string $defaultProfile = 'default',
        #[Autowire('%memories.cluster.selection.profiles%')]
        private readonly array $profiles = [],
    ) {
    }

    public function resolve(ClusterDraft $draft): ClusterMemberSelectionProfile
    {
        $params          = $draft->getParams();
        $selectionConfig = $this->extractSelectionConfig($params['member_selection'] ?? null);

        $profileKey = $selectionConfig['profile'] ?? $this->defaultProfile;
        if (!is_string($profileKey) || $profileKey === '') {
            $profileKey = $this->defaultProfile;
        }

        $options = $this->buildOptions($profileKey, $selectionConfig['overrides'] ?? []);
        $home    = $this->resolveHomeDescriptor($selectionConfig['home'] ?? null);

        return new ClusterMemberSelectionProfile($profileKey, $options, $home);
    }

    /**
     * @param mixed $config
     *
     * @return array<string, mixed>
     */
    private function extractSelectionConfig(mixed $config): array
    {
        if (!is_array($config)) {
            return [];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildOptions(string $profileKey, array $overrides): VacationSelectionOptions
    {
        $profileOverrides = $this->profiles[$profileKey] ?? [];
        if (!is_array($profileOverrides)) {
            $profileOverrides = [];
        }

        /** @var array<string, mixed> $merged */
        $merged = array_merge($profileOverrides, $overrides);

        return new VacationSelectionOptions(
            targetTotal: $this->intValue($merged, 'target_total', $this->defaultOptions->targetTotal),
            maxPerDay: $this->intValue($merged, 'max_per_day', $this->defaultOptions->maxPerDay),
            timeSlotHours: $this->intValue($merged, 'time_slot_hours', $this->defaultOptions->timeSlotHours),
            minSpacingSeconds: $this->intValue($merged, 'min_spacing_seconds', $this->defaultOptions->minSpacingSeconds),
            phashMinHamming: $this->intValue($merged, 'phash_min_hamming', $this->defaultOptions->phashMinHamming),
            maxPerStaypoint: $this->intValue($merged, 'max_per_staypoint', $this->defaultOptions->maxPerStaypoint),
            videoBonus: $this->floatValue($merged, 'video_bonus', $this->defaultOptions->videoBonus),
            faceBonus: $this->floatValue($merged, 'face_bonus', $this->defaultOptions->faceBonus),
            selfiePenalty: $this->floatValue($merged, 'selfie_penalty', $this->defaultOptions->selfiePenalty),
            qualityFloor: $this->floatValue($merged, 'quality_floor', $this->defaultOptions->qualityFloor),
        );
    }

    /**
     * @param mixed $value
     *
     * @return array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int}
     */
    private function resolveHomeDescriptor(mixed $value): array
    {
        $lat            = 0.0;
        $lon            = 0.0;
        $radius         = 0.0;
        $country        = null;
        $timezoneOffset = null;

        if (is_array($value)) {
            $lat     = $this->floatValue($value, 'lat', 0.0);
            $lon     = $this->floatValue($value, 'lon', 0.0);
            $radius  = $this->floatValue($value, 'radius_km', 0.0);
            $country = $this->stringValue($value, 'country');
            $offset  = $value['timezone_offset'] ?? null;
            if (is_int($offset)) {
                $timezoneOffset = $offset;
            } elseif (is_numeric($offset)) {
                $timezoneOffset = (int) $offset;
            }
        }

        return [
            'lat'             => $lat,
            'lon'             => $lon,
            'radius_km'       => $radius,
            'country'         => $country,
            'timezone_offset' => $timezoneOffset,
        ];
    }

    private function intValue(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function floatValue(array $values, string $key, float $default): float
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    private function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
