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
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\DefaultPoiContextAnalyzer;
use MagicSunday\Memories\Utility\DefaultPoiLabelResolver;
use MagicSunday\Memories\Utility\DefaultPoiNormalizer;
use MagicSunday\Memories\Utility\DefaultPoiScorer;
use PHPUnit\Framework\Attributes\Test;

final class DefaultPoiContextAnalyzerTest extends TestCase
{
    #[Test]
    public function resolvePrimaryPoiIgnoresNegativeDistances(): void
    {
        $analyzer = new DefaultPoiContextAnalyzer(
            new DefaultPoiNormalizer(),
            new DefaultPoiScorer(),
            new DefaultPoiLabelResolver(),
        );

        $location = $this->makeLocation(
            providerPlaceId: 'poi-negative-distance',
            displayName: 'Testort',
            lat: 48.137154,
            lon: 11.576124,
            configure: static function (Location $loc): void {
                $loc->setPois([
                    [
                        'id'    => 'node/1',
                        'name'  => 'Ungültiger Eintrag',
                        'names' => [
                            'default'    => 'Ungültiger Eintrag',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'tourism',
                        'categoryValue'  => 'museum',
                        'distanceMeters' => -5.0,
                        'tags'           => [
                            'tourism' => 'museum',
                        ],
                    ],
                    [
                        'id'    => 'node/2',
                        'name'  => 'Gültiger Eintrag',
                        'names' => [
                            'default'    => 'Gültiger Eintrag',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'tourism',
                        'categoryValue'  => 'museum',
                        'distanceMeters' => 10.0,
                        'tags'           => [
                            'tourism' => 'museum',
                        ],
                    ],
                ]);
            },
        );

        $primary = $analyzer->resolvePrimaryPoi($location);

        self::assertNotNull($primary);
        self::assertSame('Gültiger Eintrag', $primary['name']);
    }
}
