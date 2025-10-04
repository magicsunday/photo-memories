<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\NominatimReverseGeocoder;
use MagicSunday\Memories\Utility\MediaMath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class NominatimReverseGeocoderTest extends TestCase
{
    #[Test]
    public function parsesExtendedMetadata(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response   = $this->createMock(ResponseInterface::class);

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://nominatim.openstreetmap.org/reverse',
                self::callback(static function (array $options): bool {
                    self::assertSame('application/json', $options['headers']['Accept']);
                    self::assertSame('jsonv2', $options['query']['format']);
                    self::assertSame('1', $options['query']['addressdetails']);
                    self::assertSame('1', $options['query']['namedetails']);
                    self::assertSame('1', $options['query']['extratags']);

                    return true;
                })
            )
            ->willReturn($response);

        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->with(false)->willReturn([
            'place_id'     => 123456,
            'display_name' => 'Berlin, Deutschland',
            'lat'          => '52.520008',
            'lon'          => '13.404954',
            'address'      => [
                'country_code' => 'de',
                'country'      => 'Deutschland',
                'state'        => 'Berlin',
                'county'       => 'Berlin',
                'city'         => 'Berlin',
                'suburb'       => 'Mitte',
                'neighbourhood'=> 'Nikolaiviertel',
                'postcode'     => '10178',
                'road'         => 'Karl-Liebknecht-Straße',
                'house_number' => '8',
            ],
            'boundingbox' => ['52.515', '52.525', '13.4', '13.41'],
            'category'    => 'tourism',
            'type'        => 'viewpoint',
            'attribution' => 'Data © OpenStreetMap contributors',
            'licence'     => 'ODbL',
            'importance'  => 0.92,
            'osm_type'    => 'node',
            'osm_id'      => 123456,
            'extratags'   => [
                'wikidata' => 'Q64',
                'wikipedia' => 'de:Berlin',
                'timezone' => 'Europe/Berlin',
            ],
            'namedetails' => [
                'name'    => 'Berlin',
                'name:en' => 'Berlin',
                'short_name' => 'BER',
            ],
        ]);

        $geocoder = new NominatimReverseGeocoder($httpClient);
        $result   = $geocoder->reverse(52.520008, 13.404954, 'de');

        self::assertNotNull($result);
        self::assertSame('nominatim', $result->provider);
        self::assertSame('123456', $result->providerPlaceId);
        self::assertSame('Data © OpenStreetMap contributors', $result->attribution);
        self::assertSame('ODbL', $result->licence);
        self::assertSame('Europe/Berlin', $result->timezone);
        self::assertSame('node', $result->osmType);
        self::assertSame('123456', $result->osmId);
        self::assertSame('Q64', $result->wikidataId);
        self::assertSame('de:Berlin', $result->wikipedia);
        self::assertSame(['wikidata' => 'Q64', 'wikipedia' => 'de:Berlin', 'timezone' => 'Europe/Berlin'], $result->extraTags);
        self::assertSame(['name' => 'Berlin', 'name:en' => 'Berlin', 'short_name' => 'BER'], $result->altNames);
        self::assertSame(0.92, $result->confidence);

        self::assertNotNull($result->accuracyRadiusMeters);
        $expectedRadius = max(
            MediaMath::haversineDistanceInMeters(52.520008, 13.404954, 52.525, 13.404954),
            MediaMath::haversineDistanceInMeters(52.520008, 13.404954, 52.515, 13.404954),
            MediaMath::haversineDistanceInMeters(52.520008, 13.404954, 52.520008, 13.41),
            MediaMath::haversineDistanceInMeters(52.520008, 13.404954, 52.520008, 13.4),
        );
        self::assertEqualsWithDelta($expectedRadius, $result->accuracyRadiusMeters, 0.01);

        self::assertNotNull($result->refreshedAt);
    }
}
