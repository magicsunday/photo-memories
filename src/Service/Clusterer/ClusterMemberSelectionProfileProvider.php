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

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

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
        $profileKey       = $this->profiles->determineProfileKey($draft->getAlgorithm(), $requestedProfile);
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
