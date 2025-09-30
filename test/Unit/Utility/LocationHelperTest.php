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
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class LocationHelperTest extends TestCase
{
    #[Test]
    public function displayLabelPrefersWeightedPoi(): void
    {
        $helper = LocationHelper::createDefault();

        $location = $this->makeLocation(
            providerPlaceId: 'poi-weight-1',
            displayName: 'Test Location',
            lat: 52.5,
            lon: 13.4,
            configure: static function (Location $loc): void {
                $loc->setPois([
                    [
                        'id'    => 'node/1',
                        'name'  => 'Central Bakery',
                        'names' => [
                            'default'    => 'Central Bakery',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'shop',
                        'categoryValue'  => 'bakery',
                        'distanceMeters' => 15.0,
                        'tags'           => [
                            'shop' => 'bakery',
                        ],
                    ],
                    [
                        'id'    => 'node/2',
                        'name'  => 'City Museum',
                        'names' => [
                            'default'    => 'City Museum',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'tourism',
                        'categoryValue'  => 'museum',
                        'distanceMeters' => 95.0,
                        'tags'           => [
                            'tourism'  => 'museum',
                            'wikidata' => 'Q1',
                        ],
                    ],
                ]);
            },
        );

        self::assertSame('City Museum', $helper->displayLabel($location));
    }

    #[Test]
    public function majorityPoiContextHonoursWeightedSelection(): void
    {
        $helper = LocationHelper::createDefault();

        $towerLocation = $this->makeLocation(
            providerPlaceId: 'poi-weight-2',
            displayName: 'Tower Area',
            lat: 48.1,
            lon: 11.5,
            configure: static function (Location $loc): void {
                $loc->setPois([
                    [
                        'id'    => 'node/10',
                        'name'  => 'Old Town Tower',
                        'names' => [
                            'default'    => 'Old Town Tower',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'man_made',
                        'categoryValue'  => 'tower',
                        'distanceMeters' => 40.0,
                        'tags'           => [
                            'man_made' => 'tower',
                            'historic' => 'yes',
                        ],
                    ],
                    [
                        'id'    => 'node/11',
                        'name'  => 'Parking Lot',
                        'names' => [
                            'default'    => 'Parking Lot',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'amenity',
                        'categoryValue'  => 'parking',
                        'distanceMeters' => 10.0,
                        'tags'           => [
                            'amenity' => 'parking',
                        ],
                    ],
                ]);
            },
        );

        $museumLocation = $this->makeLocation(
            providerPlaceId: 'poi-weight-3',
            displayName: 'Museum District',
            lat: 48.2,
            lon: 11.6,
            configure: static function (Location $loc): void {
                $loc->setPois([
                    [
                        'id'    => 'node/20',
                        'name'  => 'City Museum',
                        'names' => [
                            'default'   => 'City Museum',
                            'localized' => [
                                'de' => 'Stadtmuseum',
                            ],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'tourism',
                        'categoryValue'  => 'museum',
                        'distanceMeters' => 110.0,
                        'tags'           => [
                            'tourism'  => 'museum',
                            'wikidata' => 'Q1',
                        ],
                    ],
                    [
                        'id'    => 'node/21',
                        'name'  => 'Central Cafe',
                        'names' => [
                            'default'    => 'Central Cafe',
                            'localized'  => [],
                            'alternates' => [],
                        ],
                        'categoryKey'    => 'amenity',
                        'categoryValue'  => 'cafe',
                        'distanceMeters' => 15.0,
                        'tags'           => [
                            'amenity' => 'cafe',
                        ],
                    ],
                ]);
            },
        );

        $mediaA = $this->makeMedia(1, '/tower.jpg', location: $towerLocation);
        $mediaB = $this->makeMedia(2, '/museum-1.jpg', location: $museumLocation);
        $mediaC = $this->makeMedia(3, '/museum-2.jpg', location: $museumLocation);

        $context = $helper->majorityPoiContext([$mediaA, $mediaB, $mediaC]);

        self::assertNotNull($context);
        self::assertSame('City Museum', $context['label']);
        self::assertSame('tourism', $context['categoryKey']);
        self::assertSame('museum', $context['categoryValue']);
        self::assertArrayHasKey('tourism', $context['tags']);
        self::assertSame('museum', $context['tags']['tourism']);
        self::assertSame('Q1', $context['tags']['wikidata']);
    }

    #[Test]
    public function displayLabelHonoursPreferredLocale(): void
    {
        $helper = LocationHelper::createDefault('de');

        $location = $this->makeLocation(
            providerPlaceId: 'poi-locale-1',
            displayName: 'Test Location',
            lat: 48.1,
            lon: 11.5,
            configure: static function (Location $loc): void {
                $loc->setPois([
                    [
                        'id'    => 'node/30',
                        'name'  => 'Old City Hall',
                        'names' => [
                            'default'   => 'Old City Hall',
                            'localized' => [
                                'de' => 'Altes Rathaus',
                                'en' => 'Old City Hall',
                            ],
                            'alternates' => ['Historisches Rathaus'],
                        ],
                        'categoryKey'    => 'historic',
                        'categoryValue'  => 'building',
                        'distanceMeters' => 20.0,
                        'tags'           => [
                            'historic' => 'yes',
                        ],
                    ],
                ]);
            },
        );

        self::assertSame('Altes Rathaus', $helper->displayLabel($location));
    }
}
