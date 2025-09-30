<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\DefaultOverpassResponseParser;
use MagicSunday\Memories\Service\Geocoding\OverpassElementFilter;
use MagicSunday\Memories\Service\Geocoding\OverpassPrimaryTagResolver;
use MagicSunday\Memories\Service\Geocoding\OverpassTagConfiguration;
use MagicSunday\Memories\Service\Geocoding\OverpassTagSelector;
use MagicSunday\Memories\Service\Geocoding\PoiNameExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultOverpassResponseParserTest extends TestCase
{
    #[Test]
    public function parsesPayloadAndOrdersByDistance(): void
    {
        $configuration = new OverpassTagConfiguration();
        $parser        = new DefaultOverpassResponseParser(
            new OverpassElementFilter(),
            new OverpassTagSelector($configuration),
            new OverpassPrimaryTagResolver($configuration),
            new PoiNameExtractor(),
        );

        $payload = [
            'elements' => [
                [
                    'type' => 'node',
                    'id'   => 1,
                    'lat'  => 1.23,
                    'lon'  => 3.21,
                    'tags' => [
                        'name'    => 'Example Park',
                        'tourism' => 'attraction',
                        'alt_name' => 'Alt One;Alt Two',
                        'name:de' => 'Beispielpark',
                        'wikidata' => 'Q123',
                    ],
                ],
                [
                    'type' => 'way',
                    'id'   => 2,
                    'center' => ['lat' => 1.24, 'lon' => 3.22],
                    'tags' => [
                        'tourism' => 'viewpoint',
                        'name:en' => 'Cliff View',
                        'alt_name' => 'Lookout',
                    ],
                ],
            ],
        ];

        $pois = $parser->parse($payload, 1.23, 3.21, null);

        self::assertCount(2, $pois);

        $first = $pois[0];
        self::assertSame('node/1', $first['id']);
        self::assertSame('Example Park', $first['name']);
        self::assertSame('tourism', $first['categoryKey']);
        self::assertSame('attraction', $first['categoryValue']);
        self::assertSame(
            [
                'tourism' => 'attraction',
                'wikidata' => 'Q123',
            ],
            $first['tags']
        );
        self::assertSame('Example Park', $first['names']['default']);
        self::assertSame(['de' => 'Beispielpark'], $first['names']['localized']);
        self::assertSame(['Alt One', 'Alt Two'], $first['names']['alternates']);
        self::assertSame(0.0, $first['distanceMeters']);

        $second = $pois[1];
        self::assertSame('way/2', $second['id']);
        self::assertSame('Cliff View', $second['name']);
        self::assertSame('tourism', $second['categoryKey']);
        self::assertSame('viewpoint', $second['categoryValue']);
        self::assertSame(['tourism' => 'viewpoint'], $second['tags']);
        self::assertSame(['en' => 'Cliff View'], $second['names']['localized']);
        self::assertSame(['Lookout'], $second['names']['alternates']);
        self::assertGreaterThan(0.0, $second['distanceMeters']);
        self::assertGreaterThan($first['distanceMeters'], $second['distanceMeters']);
    }

    #[Test]
    public function appliesLimitToResultSet(): void
    {
        $configuration = new OverpassTagConfiguration();
        $parser        = new DefaultOverpassResponseParser(
            new OverpassElementFilter(),
            new OverpassTagSelector($configuration),
            new OverpassPrimaryTagResolver($configuration),
            new PoiNameExtractor(),
        );

        $payload = [
            'elements' => [
                [
                    'type' => 'node',
                    'id'   => 1,
                    'lat'  => 1.23,
                    'lon'  => 3.21,
                    'tags' => [
                        'tourism' => 'attraction',
                    ],
                ],
                [
                    'type' => 'node',
                    'id'   => 3,
                    'lat'  => 1.24,
                    'lon'  => 3.22,
                    'tags' => [
                        'tourism' => 'viewpoint',
                    ],
                ],
            ],
        ];

        $pois = $parser->parse($payload, 1.23, 3.21, 1);

        self::assertCount(1, $pois);
        self::assertSame('node/1', $pois[0]['id']);
    }
}
