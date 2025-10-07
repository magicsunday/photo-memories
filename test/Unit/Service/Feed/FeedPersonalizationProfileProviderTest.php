<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use InvalidArgumentException;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfileProvider;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FeedPersonalizationProfileProviderTest extends TestCase
{
    #[Test]
    public function returnsDefaultProfileWhenKeyUnknown(): void
    {
        $provider = new FeedPersonalizationProfileProvider([
            'default' => [
                'min_score'            => 0.3,
                'min_members'          => 2,
                'max_per_day'          => 5,
                'max_total'            => 40,
                'max_per_algorithm'    => 8,
                'quality_floor'        => 0.2,
                'people_coverage_min'  => 0.1,
                'recent_days'          => 30,
                'stale_days'           => 365,
                'recent_score_bonus'   => 0.02,
                'stale_score_penalty'  => 0.04,
            ],
            'familie' => [
                'min_score'            => 0.25,
                'min_members'          => 1,
                'max_per_day'          => 6,
                'max_total'            => 48,
                'max_per_algorithm'    => 10,
                'quality_floor'        => 0.15,
                'people_coverage_min'  => 0.05,
                'recent_days'          => 14,
                'stale_days'           => 120,
                'recent_score_bonus'   => 0.05,
                'stale_score_penalty'  => 0.03,
            ],
        ]);

        $default = $provider->getProfile(null);
        self::assertSame('default', $default->getKey());
        self::assertSame(['default', 'familie'], $provider->listProfiles());

        $fallback = $provider->getProfile('unbekannt');
        self::assertSame($default, $fallback);
    }

    #[Test]
    public function throwsWhenDefaultMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing default personalisation profile');

        new FeedPersonalizationProfileProvider([
            'familie' => [
                'min_score'            => 0.25,
                'min_members'          => 1,
                'max_per_day'          => 6,
                'max_total'            => 48,
                'max_per_algorithm'    => 10,
                'quality_floor'        => 0.15,
                'people_coverage_min'  => 0.05,
                'recent_days'          => 14,
                'stale_days'           => 120,
                'recent_score_bonus'   => 0.05,
                'stale_score_penalty'  => 0.03,
            ],
        ]);
    }
}
