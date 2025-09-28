<?php
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
        $helper = new LocationHelper();

        $location = $this->makeLocation(
            providerPlaceId: 'poi-weight-1',
            displayName: 'Test Location',
            lat: 52.5,
            lon: 13.4,
            configure: static function (Location $loc): void {
                $loc->setPois([
                    [
                        'id' => 'node/1',
                        'name' => 'Central Bakery',
                        'categoryKey' => 'shop',
                        'categoryValue' => 'bakery',
                        'distanceMeters' => 15.0,
                        'tags' => [
                            'shop' => 'bakery',
                        ],
                    ],
                    [
                        'id' => 'node/2',
                        'name' => 'City Museum',
                        'categoryKey' => 'tourism',
                        'categoryValue' => 'museum',
                        'distanceMeters' => 95.0,
                        'tags' => [
                            'tourism' => 'museum',
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
        $helper = new LocationHelper();

        $towerLocation = $this->makeLocation(
            providerPlaceId: 'poi-weight-2',
            displayName: 'Tower Area',
            lat: 48.1,
            lon: 11.5,
            configure: static function (Location $loc): void {
                $loc->setPois([
                    [
                        'id' => 'node/10',
                        'name' => 'Old Town Tower',
                        'categoryKey' => 'man_made',
                        'categoryValue' => 'tower',
                        'distanceMeters' => 40.0,
                        'tags' => [
                            'man_made' => 'tower',
                            'historic' => 'yes',
                        ],
                    ],
                    [
                        'id' => 'node/11',
                        'name' => 'Parking Lot',
                        'categoryKey' => 'amenity',
                        'categoryValue' => 'parking',
                        'distanceMeters' => 10.0,
                        'tags' => [
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
                        'id' => 'node/20',
                        'name' => 'City Museum',
                        'categoryKey' => 'tourism',
                        'categoryValue' => 'museum',
                        'distanceMeters' => 110.0,
                        'tags' => [
                            'tourism' => 'museum',
                            'wikidata' => 'Q1',
                        ],
                    ],
                    [
                        'id' => 'node/21',
                        'name' => 'Central Cafe',
                        'categoryKey' => 'amenity',
                        'categoryValue' => 'cafe',
                        'distanceMeters' => 15.0,
                        'tags' => [
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
}
