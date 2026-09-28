<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\OverpassPrimaryTagResolver;
use MagicSunday\Memories\Service\Geocoding\OverpassTagConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverpassPrimaryTagResolverTest extends TestCase
{
    #[Test]
    public function resolvesFirstMatchingTagBasedOnConfigurationOrder(): void
    {
        $configuration = new OverpassTagConfiguration();

        $resolver = new OverpassPrimaryTagResolver($configuration);

        $result = $resolver->resolve([
            'man_made' => 'tower',
            'historic' => 'castle',
        ]);

        self::assertNotNull($result);
        self::assertSame('historic', $result['key']);
        self::assertSame('castle', $result['value']);
    }

    #[Test]
    public function returnsNullWhenNoAllowedTagPresent(): void
    {
        $configuration = new OverpassTagConfiguration([
            ['tourism' => ['viewpoint']],
        ]);

        $resolver = new OverpassPrimaryTagResolver($configuration);

        self::assertNull($resolver->resolve(['amenity' => 'cafe']));
    }
}
