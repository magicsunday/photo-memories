<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\LocationSimilarityStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class LocationSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function clustersMediaByLocalityWithPoiMetadata(): void
    {
        $strategy = new LocationSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            radiusMeters: 200.0,
            minItemsPerPlace: 3,
            maxSpanHours: 12,
        );

        $museum = $this->makeLocation(
            providerPlaceId: 'berlin-museum',
            displayName: 'Neues Museum',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
            suburb: 'Mitte',
        );
        $museum->setPois([
            [
                'name'  => 'Museum Island',
                'names' => [
                    'default'   => 'Museum Island',
                    'localized' => [
                        'de' => 'Museumsinsel',
                    ],
                    'alternates' => [],
                ],
                'categoryKey'   => 'tourism',
                'categoryValue' => 'museum',
                'tags'          => ['wikidata' => 'Q1234'],
            ],
        ]);

        $mediaItems = [
            $this->createMedia(801, '2023-04-01 09:00:00', 52.5201, 13.4049, $museum),
            $this->createMedia(802, '2023-04-01 09:15:00', 52.5202, 13.4050, $museum),
            $this->createMedia(803, '2023-04-01 09:35:00', 52.5203, 13.4051, $museum),
            $this->createMedia(804, '2023-04-01 10:05:00', 52.5204, 13.4052, $museum),
            // Below the locality threshold: different place
            $this->createMedia(805, '2023-04-01 11:00:00', 48.1371, 11.5753, $this->makeLocation(
                providerPlaceId: 'munich-park',
                displayName: 'Englischer Garten',
                lat: 48.1371,
                lon: 11.5753,
                city: 'Munich',
                country: 'Germany',
            )),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('location_similarity', $cluster->getAlgorithm());
        self::assertSame([801, 802, 803, 804], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame('suburb:Mitte|city:Berlin|country:Germany', $params['place_key']);
        self::assertSame('Museum Island', $params['place']);
        self::assertSame('Museum Island', $params['poi_label']);
        self::assertSame('tourism', $params['poi_category_key']);
        self::assertSame('museum', $params['poi_category_value']);
        self::assertSame(['wikidata' => 'Q1234'], $params['poi_tags']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-04-01 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-04-01 10:05:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.52025, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(13.40505, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function fallsBackToSpatialWindowsForItemsWithoutLocality(): void
    {
        $strategy = new LocationSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            radiusMeters: 250.0,
            minItemsPerPlace: 3,
            maxSpanHours: 1,
        );

        $mediaItems = [
            $this->createMedia(901, '2023-05-01 10:00:00', 48.1371, 11.5753, null),
            $this->createMedia(902, '2023-05-01 10:20:00', 48.1372, 11.5754, null),
            $this->createMedia(903, '2023-05-01 10:35:00', 48.1373, 11.5755, null),
            // Outside of the spatial window due to distance
            $this->createMedia(904, '2023-05-01 10:40:00', 48.1400, 11.5800, null),
            // Different day bucket
            $this->createMedia(905, '2023-05-02 09:00:00', 48.2000, 11.6000, null),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('location_similarity', $cluster->getAlgorithm());
        self::assertSame([901, 902, 903], $cluster->getMembers());

        $params        = $cluster->getParams();
        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-05-01 10:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-05-01 10:35:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);
        self::assertArrayNotHasKey('place', $params);
        self::assertArrayNotHasKey('place_key', $params);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(48.1372, $centroid['lat'], 0.00001);
        self::assertEqualsWithDelta(11.5754, $centroid['lon'], 0.00001);
    }

    #[Test]
    public function usesGeoCellMetadataToPreGroupSpatialWindows(): void
    {
        $strategy = new LocationSimilarityStrategy(
            locHelper: LocationHelper::createDefault(),
            radiusMeters: 250.0,
            minItemsPerPlace: 3,
            maxSpanHours: 1,
        );

        $mediaItems = [
            $this->createMedia(
                931,
                '2023-05-03 12:00:00',
                48.2082,
                16.3738,
                null,
                static function (Media $media): void {
                    $media->setGeoCell8('u2edk8h2');
                }
            ),
            $this->createMedia(
                932,
                '2023-05-03 12:15:00',
                48.2083,
                16.3739,
                null,
                static function (Media $media): void {
                    $media->setGeoCell8('u2edk8h2');
                }
            ),
            $this->createMedia(
                933,
                '2023-05-03 12:40:00',
                48.2084,
                16.3740,
                null,
                static function (Media $media): void {
                    $media->setGeoCell8('u2edk8h2');
                }
            ),
            $this->createMedia(
                934,
                '2023-05-03 12:45:00',
                48.2150,
                16.3800,
                null,
                static function (Media $media): void {
                    $media->setGeoCell8('u2edk8x0');
                }
            ),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame([931, 932, 933], $cluster->getMembers());

        $params        = $cluster->getParams();
        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-05-03 12:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-05-03 12:40:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);
    }

    private function createMedia(
        int $id,
        string $takenAt,
        float $lat,
        float $lon,
        ?Location $location,
        ?callable $configure = null,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('location-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: $configure,
        );
    }
}
