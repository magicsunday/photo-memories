<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Utility;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Utility\Contract\PoiContextAnalyzerInterface;
use MagicSunday\Memories\Utility\Contract\PoiLabelResolverInterface;
use MagicSunday\Memories\Utility\DefaultLocationLabelResolver;
use PHPUnit\Framework\TestCase;

final class DefaultLocationLabelResolverTest extends TestCase
{
    public function testMajorityLabelSkipsNonStringPoiLabel(): void
    {
        $analyzer = new class implements PoiContextAnalyzerInterface {
            public function resolvePrimaryPoi(Location $location): ?array
            {
                return null;
            }

            public function bestLabelForLocation(Location $location): ?string
            {
                return null;
            }

            public function majorityPoiContext(array $members): ?array
            {
                return [
                    'label' => 123,
                    'categoryKey' => null,
                    'categoryValue' => null,
                    'tags' => [],
                ];
            }
        };

        $labelResolver = new class implements PoiLabelResolverInterface {
            public function preferredLabel(array $poi): ?string
            {
                return null;
            }
        };

        $resolver = new DefaultLocationLabelResolver($analyzer, $labelResolver);

        self::assertNull($resolver->majorityLabel([]));
    }

    public function testMajorityLabelHandlesNonStringCategoryValue(): void
    {
        $analyzer = new class implements PoiContextAnalyzerInterface {
            public function resolvePrimaryPoi(Location $location): ?array
            {
                return null;
            }

            public function bestLabelForLocation(Location $location): ?string
            {
                return null;
            }

            public function majorityPoiContext(array $members): ?array
            {
                return [
                    'label' => '  Stadtpark  ',
                    'categoryKey' => null,
                    'categoryValue' => 456,
                    'tags' => [],
                ];
            }
        };

        $labelResolver = new class implements PoiLabelResolverInterface {
            public function preferredLabel(array $poi): ?string
            {
                return null;
            }
        };

        $resolver = new DefaultLocationLabelResolver($analyzer, $labelResolver);

        self::assertSame('Stadtpark', $resolver->majorityLabel([]));
    }
}
