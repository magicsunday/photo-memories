<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Selection;

use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

final class SelectionPolicyProviderConfigTest extends TestCase
{
    #[Test]
    public function vacationProfileMatchesConfigurationDefaults(): void
    {
        $configuration = $this->selectionConfiguration();

        $provider = new SelectionPolicyProvider(
            profiles: $configuration['profiles'],
            algorithmProfiles: $configuration['algorithm_profiles'],
            defaultProfile: $configuration['default_profile'],
        );

        $policy = $provider->forAlgorithm('vacation');

        self::assertSame('vacation', $policy->getProfileKey());
        self::assertSame(60, $policy->getTargetTotal());
        self::assertSame(36, $policy->getMinimumTotal());
        self::assertSame(4, $policy->getMaxPerDay());
        self::assertSame(4.0, $policy->getTimeSlotHours());
        self::assertSame(3600, $policy->getMinSpacingSeconds());
        self::assertSame(11, $policy->getPhashMinHamming());
        self::assertSame(1, $policy->getMaxPerStaypoint());
        self::assertSame(2, $policy->getRelaxedMaxPerStaypoint());
        self::assertSame(0.56, $policy->getQualityFloor());
        self::assertSame(0.22, $policy->getVideoBonus());
        self::assertSame(0.34, $policy->getFaceBonus());
        self::assertSame(0.26, $policy->getSelfiePenalty());
        self::assertSame(1, $policy->getCoreDayBonus());
        self::assertSame(1, $policy->getPeripheralDayPenalty());
        self::assertSame(0.35, $policy->getPhashPercentile());
        self::assertSame(0.45, $policy->getSpacingProgressFactor());
        self::assertSame(0.05, $policy->getCohortPenalty());
    }

    #[Test]
    public function highlightsProfileMatchesConfigurationDefaults(): void
    {
        $configuration = $this->selectionConfiguration();

        $provider = new SelectionPolicyProvider(
            profiles: $configuration['profiles'],
            defaultProfile: $configuration['default_profile'],
            algorithmProfiles: $configuration['algorithm_profiles'],
        );

        $policy = $provider->forAlgorithm('highlights');

        self::assertSame('highlights', $policy->getProfileKey());
        self::assertSame(30, $policy->getTargetTotal());
        self::assertSame(18, $policy->getMinimumTotal());
        self::assertSame(3, $policy->getMaxPerDay());
        self::assertSame(3.0, $policy->getTimeSlotHours());
        self::assertSame(3300, $policy->getMinSpacingSeconds());
        self::assertSame(10, $policy->getPhashMinHamming());
        self::assertSame(2, $policy->getMaxPerStaypoint());
        self::assertSame(0.6, $policy->getQualityFloor());
        self::assertSame(1, $policy->getCoreDayBonus());
        self::assertSame(1, $policy->getPeripheralDayPenalty());
        self::assertSame(0.35, $policy->getPhashPercentile());
        self::assertSame(0.5, $policy->getSpacingProgressFactor());
        self::assertSame(0.05, $policy->getCohortPenalty());
    }

    #[Test]
    public function storylineSpecificProfileOverridesDefault(): void
    {
        $configuration = $this->selectionConfiguration();
        $configuration['algorithm_profiles']['vacation'] = [
            'default' => 'vacation',
            'vacation.transit' => 'location',
            'transit' => 'location',
        ];

        $provider = new SelectionPolicyProvider(
            profiles: $configuration['profiles'],
            defaultProfile: $configuration['default_profile'],
            algorithmProfiles: $configuration['algorithm_profiles'],
        );

        $policy = $provider->forAlgorithm('vacation', 'vacation.transit');

        self::assertSame('location', $policy->getProfileKey());
        self::assertSame(48, $policy->getTargetTotal());
    }

    /**
     * @return array{profiles: array<string, array<string, int|float|string|null>>, algorithm_profiles: array<string, string>, default_profile: string}
     */
    private function selectionConfiguration(): array
    {
        $raw = Yaml::parseFile(__DIR__ . '/../../../../../config/parameters/selection.yaml');

        $parameters = $raw['parameters'];

        return [
            'profiles' => $parameters['memories.selection.profiles'],
            'algorithm_profiles' => $parameters['memories.selection.algorithm_profiles'],
            'default_profile' => $parameters['memories.selection.default_profile'],
        ];
    }
}
