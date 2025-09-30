<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\OverpassTagConfiguration;
use MagicSunday\Memories\Service\Geocoding\OverpassTagSelector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverpassTagSelectorTest extends TestCase
{
    #[Test]
    public function keepsAllowedTagsAndAuxiliaryOnes(): void
    {
        $configuration = new OverpassTagConfiguration();
        $selector      = new OverpassTagSelector($configuration);

        $result = $selector->select([
            'tourism'  => 'museum',
            'amenity'  => 'cafe',
            'alt_name' => 'Alt A ; Alt B',
            'name'     => 'Museum of Art',
            'name:de'  => 'Kunstmuseum',
            'name:en'  => 'Museum of Art',
            'wikidata' => 'Q12345',
        ]);

        self::assertSame([
            'tourism'  => 'museum',
            'wikidata' => 'Q12345',
        ], $result['tags']);

        self::assertSame('Museum of Art', $result['names']['default']);
        self::assertSame([
            'de' => 'Kunstmuseum',
            'en' => 'Museum of Art',
        ], $result['names']['localized']);
        self::assertSame(['Alt A', 'Alt B'], $result['names']['alternates']);
    }

    #[Test]
    public function ignoresDisallowedTagsAndEmptyNames(): void
    {
        $configuration = new OverpassTagConfiguration([
            ['tourism' => ['viewpoint']],
        ]);
        $selector      = new OverpassTagSelector($configuration);

        $result = $selector->select([
            'tourism'   => 'viewpoint',
            'alt_name'  => ' ; Alt C ; Alt C',
            'name:fr'   => '',
            'name:es'   => ' Vista ',
            'wikidata'  => 'Q99',
            'randomtag' => 'value',
        ]);

        self::assertSame([
            'tourism'  => 'viewpoint',
            'wikidata' => 'Q99',
        ], $result['tags']);

        self::assertNull($result['names']['default']);
        self::assertSame(['es' => ' Vista '], $result['names']['localized']);
        self::assertSame(['Alt C'], $result['names']['alternates']);
    }
}
