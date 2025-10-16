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
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;

use function filter_var;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;

/**
 * Resolves the active selection profile and options for a cluster draft.
 */
final class ClusterMemberSelectionProfileProvider
{
    public function __construct(private readonly SelectionProfileProvider $profiles)
    {
    }

    public function resolve(ClusterDraft $draft): ClusterMemberSelectionProfile
    {
        $params          = $draft->getParams();
        $selectionConfig = $this->extractSelectionConfig($params['member_selection'] ?? null);

        $requestedProfile = $this->stringValue($selectionConfig, 'profile');
        $context          = $this->profileContext($draft->getParams(), $selectionConfig);
        $profileKey       = $this->profiles->determineProfileKey($draft->getAlgorithm(), $requestedProfile, $context);
        $overrides        = $this->extractOverrides($selectionConfig['overrides'] ?? null);
        $options          = $this->profiles->createOptions($profileKey, $overrides);
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
     * @param array<string, int|float|string|bool|array|null> $draftParams
     * @param array<string, mixed>                            $selectionConfig
     *
     * @return array<string, int|bool>
     */
    private function profileContext(array $draftParams, array $selectionConfig): array
    {
        $context = [];

        $awayDays = $this->intValue($draftParams, 'away_days');
        if ($awayDays !== null) {
            $context['away_days'] = $awayDays;
        }

        $nights = $this->intValue($draftParams, 'nights');
        if ($nights !== null) {
            $context['nights'] = $nights;
        }

        $weekend = $this->boolValue($draftParams, 'weekend_getaway');
        if ($weekend !== null) {
            $context['weekend_getaway'] = $weekend;
        }

        $manualContext = $selectionConfig['context'] ?? null;
        if (is_array($manualContext)) {
            $manualAwayDays = $this->intValue($manualContext, 'away_days');
            if ($manualAwayDays !== null) {
                $context['away_days'] = $manualAwayDays;
            }

            $manualNights = $this->intValue($manualContext, 'nights');
            if ($manualNights !== null) {
                $context['nights'] = $manualNights;
            }

            $manualWeekend = $this->boolValue($manualContext, 'weekend_getaway');
            if ($manualWeekend !== null) {
                $context['weekend_getaway'] = $manualWeekend;
            }
        }

        $decision = $selectionConfig['decision'] ?? null;
        if (is_array($decision)) {
            $decisionContext = $decision['context'] ?? null;
            if (is_array($decisionContext)) {
                $decisionAwayDays = $this->intValue($decisionContext, 'away_days');
                if ($decisionAwayDays !== null) {
                    $context['away_days'] = $decisionAwayDays;
                }

                $decisionNights = $this->intValue($decisionContext, 'nights');
                if ($decisionNights !== null) {
                    $context['nights'] = $decisionNights;
                }

                $decisionWeekend = $this->boolValue($decisionContext, 'weekend_getaway');
                if ($decisionWeekend !== null) {
                    $context['weekend_getaway'] = $decisionWeekend;
                }
            }
        }

        return $context;
    }

    /**
     * @param mixed $overrides
     *
     * @return array<string, mixed>
     */
    private function extractOverrides(mixed $overrides): array
    {
        if (!is_array($overrides)) {
            return [];
        }

        return $overrides;
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

    /**
     * @param array<string, mixed> $values
     */
    private function intValue(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function boolValue(array $values, string $key): ?bool
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_numeric($value) || is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                return $filtered;
            }
        }

        return null;
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
