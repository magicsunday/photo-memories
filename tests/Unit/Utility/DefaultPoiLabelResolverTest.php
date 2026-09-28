<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Utility;

use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\DefaultPoiLabelResolver;
use PHPUnit\Framework\Attributes\Test;

final class DefaultPoiLabelResolverTest extends TestCase
{
    #[Test]
    public function prefersConfiguredLocale(): void
    {
        $resolver = new DefaultPoiLabelResolver('de-DE');

        $label = $resolver->preferredLabel([
            'name'  => 'Central Park',
            'names' => [
                'default'   => 'Central Park',
                'localized' => [
                    'en'    => 'Central Park',
                    'de-de' => 'Zentralpark',
                ],
                'alternates' => ['Parque Central'],
            ],
            'categoryKey'   => 'leisure',
            'categoryValue' => 'park',
            'tags'          => [],
        ]);

        self::assertSame('Zentralpark', $label);
    }

    #[Test]
    public function fallsBackToPrimaryNameWhenNoNames(): void
    {
        $resolver = new DefaultPoiLabelResolver('fr');

        $label = $resolver->preferredLabel([
            'name'  => 'City Museum',
            'names' => [
                'default'    => null,
                'localized'  => [],
                'alternates' => [],
            ],
            'categoryKey'   => 'tourism',
            'categoryValue' => 'museum',
            'tags'          => [],
        ]);

        self::assertSame('City Museum', $label);
    }
}
