<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use InvalidArgumentException;

use function array_key_exists;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Provides configured personalisation profiles for feed generation.
 */
final class FeedPersonalizationProfileProvider
{
    /** @var array<string, FeedPersonalizationProfile> */
    private array $profiles = [];

    public function __construct(
        array $profiles,
        private readonly string $defaultProfile = 'default',
    ) {
        foreach ($profiles as $key => $config) {
            if (!is_string($key) || $key === '' || !is_array($config)) {
                continue;
            }

            $this->profiles[$key] = $this->createProfile($key, $config);
        }

        if (!array_key_exists($this->defaultProfile, $this->profiles)) {
            throw new InvalidArgumentException('Missing default personalisation profile: ' . $this->defaultProfile);
        }
    }

    public function getProfile(?string $profileKey = null): FeedPersonalizationProfile
    {
        $resolved = $profileKey;
        if ($resolved === null || !array_key_exists($resolved, $this->profiles)) {
            $resolved = $this->defaultProfile;
        }

        return $this->profiles[$resolved];
    }

    /**
     * @return list<string>
     */
    public function listProfiles(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createProfile(string $key, array $config): FeedPersonalizationProfile
    {
        $minScore            = $this->floatValue($config, 'min_score', 0.0);
        $minMembers          = $this->intValue($config, 'min_members', 1);
        $maxPerDay           = $this->intValue($config, 'max_per_day', 6);
        $maxTotal            = $this->intValue($config, 'max_total', 60);
        $maxPerAlgorithm     = $this->intValue($config, 'max_per_algorithm', 12);
        $qualityFloor        = $this->floatValue($config, 'quality_floor', 0.0);
        $peopleCoverage      = $this->floatValue($config, 'people_coverage_min', 0.0);
        $recentDays          = $this->intValue($config, 'recent_days', 0);
        $staleDays           = $this->intValue($config, 'stale_days', 0);
        $recentScoreBonus    = $this->floatValue($config, 'recent_score_bonus', 0.0);
        $staleScorePenalty   = $this->floatValue($config, 'stale_score_penalty', 0.0);

        return new FeedPersonalizationProfile(
            $key,
            $minScore,
            $minMembers,
            $maxPerDay,
            $maxTotal,
            $maxPerAlgorithm,
            $qualityFloor,
            $peopleCoverage,
            $recentDays,
            $staleDays,
            $recentScoreBonus,
            $staleScorePenalty,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function floatValue(array $config, string $key, float $fallback): float
    {
        if (!array_key_exists($key, $config)) {
            return $fallback;
        }

        $value = $config[$key];
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function intValue(array $config, string $key, int $fallback): int
    {
        if (!array_key_exists($key, $config)) {
            return $fallback;
        }

        $value = $config[$key];
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $fallback;
    }
}
