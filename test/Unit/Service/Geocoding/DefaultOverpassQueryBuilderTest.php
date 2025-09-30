<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\DefaultOverpassQueryBuilder;
use MagicSunday\Memories\Service\Geocoding\OverpassTagConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultOverpassQueryBuilderTest extends TestCase
{
    #[Test]
    public function buildsQueryUsingConfiguredCombinations(): void
    {
        $configuration = new OverpassTagConfiguration([
            [
                'tourism' => ['attraction'],
                'historic' => ['castle', 'ruins'],
            ],
            [
                'tourism' => ['theme_park'],
            ],
        ]);

        $builder = new DefaultOverpassQueryBuilder($configuration, 15);

        $query = $builder->build(1.23, 3.21, 250, null);

        self::assertStringContainsString('[out:json][timeout:15];(', $query);
        self::assertStringContainsString('nwr(around:250,1.2300000,3.2100000)["tourism"="theme_park"]', $query);
        self::assertStringContainsString('["tourism"="attraction"]["historic"~"^(castle|ruins)$"]', $query);
        self::assertStringEndsWith(');out tags center;', $query);
    }
}
