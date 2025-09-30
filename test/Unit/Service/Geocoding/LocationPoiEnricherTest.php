<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use BadMethodCallException;
use MagicSunday\Memories\Service\Geocoding\DefaultOverpassQueryBuilder;
use MagicSunday\Memories\Service\Geocoding\DefaultOverpassResponseParser;
use MagicSunday\Memories\Service\Geocoding\GeocodeResult;
use MagicSunday\Memories\Service\Geocoding\LocationPoiEnricher;
use MagicSunday\Memories\Service\Geocoding\OverpassClient;
use MagicSunday\Memories\Service\Geocoding\OverpassElementFilter;
use MagicSunday\Memories\Service\Geocoding\OverpassPrimaryTagResolver;
use MagicSunday\Memories\Service\Geocoding\OverpassTagConfiguration;
use MagicSunday\Memories\Service\Geocoding\OverpassTagSelector;
use MagicSunday\Memories\Service\Geocoding\PoiNameExtractor;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\MediaMath;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

use function array_map;
use function round;

final class LocationPoiEnricherTest extends TestCase
{
    #[Test]
    public function enrichesLocationWithNearbyPois(): void
    {
        $location = $this->makeLocation('place-1', 'Berlin', 52.52, 13.405);
        $geocode  = $this->createGeocodeResult(52.52, 13.405);

        $response = $this->createResponse([
            'elements' => [
                [
                    'type' => 'node',
                    'id'   => 123,
                    'lat'  => 52.5205,
                    'lon'  => 13.4049,
                    'tags' => [
                        'name'     => 'Brandenburg Gate',
                        'name:de'  => 'Brandenburger Tor',
                        'name:en'  => 'Brandenburg Gate',
                        'alt_name' => 'Porta Brandeburghese;Brandenburger Tor',
                        'tourism'  => 'attraction',
                        'historic' => 'monument',
                        'wikidata' => 'Q82424',
                    ],
                ],
            ],
        ]);

        $enricher = $this->createEnricher([$response]);

        $usedNetwork = $enricher->enrich($location, $geocode);
        $pois        = $location->getPois();

        self::assertTrue($usedNetwork);
        self::assertIsArray($pois);
        self::assertCount(1, $pois);

        $expectedDistance = round(MediaMath::haversineDistanceInMeters(52.52, 13.405, 52.5205, 13.4049), 2);

        self::assertSame('node/123', $pois[0]['id']);
        self::assertSame('Brandenburg Gate', $pois[0]['name']);
        self::assertSame([
            'default'   => 'Brandenburg Gate',
            'localized' => [
                'de' => 'Brandenburger Tor',
                'en' => 'Brandenburg Gate',
            ],
            'alternates' => ['Porta Brandeburghese', 'Brandenburger Tor'],
        ], $pois[0]['names']);
        self::assertSame('tourism', $pois[0]['categoryKey']);
        self::assertSame('attraction', $pois[0]['categoryValue']);
        self::assertSame($expectedDistance, $pois[0]['distanceMeters']);
        self::assertSame([
            'tourism'  => 'attraction',
            'historic' => 'monument',
            'wikidata' => 'Q82424',
        ], $pois[0]['tags']);
    }

    #[Test]
    public function keepsClosestPoisWhenQueryLimitIsUnlimited(): void
    {
        $location = $this->makeLocation('place-4', 'Berlin', 52.52, 13.405);
        $geocode  = $this->createGeocodeResult(52.52, 13.405);

        $response = $this->createResponse([
            'elements' => [
                [
                    'type' => 'node',
                    'id'   => 1,
                    'lat'  => 52.5201,
                    'lon'  => 13.405,
                    'tags' => [
                        'name'    => 'City Museum',
                        'tourism' => 'museum',
                    ],
                ],
                [
                    'type' => 'node',
                    'id'   => 2,
                    'lat'  => 52.52005,
                    'lon'  => 13.4051,
                    'tags' => [
                        'name'    => 'Nearby Cafe',
                        'amenity' => 'cafe',
                    ],
                ],
                [
                    'type' => 'node',
                    'id'   => 3,
                    'lat'  => 52.5206,
                    'lon'  => 13.406,
                    'tags' => [
                        'name'    => 'Skyline Viewpoint',
                        'tourism' => 'viewpoint',
                    ],
                ],
                [
                    'type' => 'node',
                    'id'   => 4,
                    'lat'  => 52.522,
                    'lon'  => 13.41,
                    'tags' => [
                        'name'     => 'Historic Tower',
                        'man_made' => 'tower',
                    ],
                ],
            ],
        ]);

        $enricher = $this->createEnricher([$response], maxPois: 2, fetchLimitMultiplier: 0.0);

        $usedNetwork = $enricher->enrich($location, $geocode);
        $pois        = $location->getPois();

        self::assertTrue($usedNetwork);
        self::assertIsArray($pois);
        self::assertCount(2, $pois);
        self::assertSame('node/1', $pois[0]['id']);
        self::assertSame('node/3', $pois[1]['id']);
        self::assertNotContains('node/2', array_map(static fn (array $poi): string => $poi['id'], $pois));
    }

    #[Test]
    public function keepsSightseeingCategoriesAndDropsDisallowedOnes(): void
    {
        $location = $this->makeLocation('place-5', 'Dresden', 51.05, 13.74);
        $geocode  = $this->createGeocodeResult(51.05, 13.74);

        $response = $this->createResponse([
            'elements' => [
                [
                    'type' => 'node',
                    'id'   => 10,
                    'lat'  => 51.0505,
                    'lon'  => 13.7405,
                    'tags' => [
                        'name'    => 'City Museum',
                        'tourism' => 'museum',
                    ],
                ],
                [
                    'type' => 'node',
                    'id'   => 11,
                    'lat'  => 51.0507,
                    'lon'  => 13.741,
                    'tags' => [
                        'name'    => 'Panorama View',
                        'tourism' => 'viewpoint',
                    ],
                ],
                [
                    'type' => 'node',
                    'id'   => 12,
                    'lat'  => 51.0515,
                    'lon'  => 13.742,
                    'tags' => [
                        'name'     => 'Lighthouse Tower',
                        'man_made' => 'tower',
                    ],
                ],
                [
                    'type' => 'node',
                    'id'   => 13,
                    'lat'  => 51.0502,
                    'lon'  => 13.7395,
                    'tags' => [
                        'name'    => 'Corner Cafe',
                        'amenity' => 'cafe',
                    ],
                ],
            ],
        ]);

        $enricher = $this->createEnricher([$response], maxPois: 5, fetchLimitMultiplier: 0.0);

        $usedNetwork = $enricher->enrich($location, $geocode);
        $pois        = $location->getPois();

        self::assertTrue($usedNetwork);
        self::assertIsArray($pois);
        self::assertCount(3, $pois);

        $ids = array_map(static fn (array $poi): string => $poi['id'], $pois);
        self::assertSame(['node/10', 'node/11', 'node/12'], $ids);

        $categoryKeys = array_map(static fn (array $poi): ?string => $poi['categoryKey'], $pois);
        $categoryValues = array_map(static fn (array $poi): ?string => $poi['categoryValue'], $pois);

        self::assertSame(['tourism', 'tourism', 'man_made'], $categoryKeys);
        self::assertSame(['museum', 'viewpoint', 'tower'], $categoryValues);
    }

    #[Test]
    public function marksAttemptWhenNetworkReturnsNoResults(): void
    {
        $location = $this->makeLocation('place-2', 'Hamburg', 53.55, 9.993);
        $geocode  = $this->createGeocodeResult(53.55, 9.993);

        $response = $this->createResponse([
            'elements' => [],
        ]);

        $enricher = $this->createEnricher([$response]);

        $usedNetwork = $enricher->enrich($location, $geocode);

        self::assertTrue($usedNetwork);
        self::assertSame([], $location->getPois());
    }

    #[Test]
    public function keepsExistingPoisWhenNetworkWasNotUsed(): void
    {
        $existingPois = [
            [
                'id'    => 'node/999',
                'name'  => 'Existing Museum',
                'names' => [
                    'default'    => 'Existing Museum',
                    'localized'  => [],
                    'alternates' => [],
                ],
                'categoryKey'    => 'tourism',
                'categoryValue'  => 'museum',
                'distanceMeters' => 10.0,
            ],
        ];

        $location = $this->makeLocation('place-3', 'Munich', 48.137, 11.575, configure: static fn ($loc) => $loc->setPois($existingPois));
        $geocode  = $this->createGeocodeResult(48.137, 11.575);

        $enricher = $this->createEnricher([], maxPois: 0);

        $usedNetwork = $enricher->enrich($location, $geocode);

        self::assertFalse($usedNetwork);
        self::assertSame($existingPois, $location->getPois());
    }

    /**
     * @param list<FakeHttpResponse> $responses
     */
    private function createEnricher(array $responses, int $radius = 250, int $maxPois = 15, float $fetchLimitMultiplier = 3.0): LocationPoiEnricher
    {
        $configuration = new OverpassTagConfiguration();
        $client        = new OverpassClient(
            http: new FakeHttpClient($responses),
            queryBuilder: new DefaultOverpassQueryBuilder($configuration, 25),
            responseParser: new DefaultOverpassResponseParser(
                new OverpassElementFilter(),
                new OverpassTagSelector($configuration),
                new OverpassPrimaryTagResolver($configuration),
                new PoiNameExtractor(),
            ),
            httpTimeout: 5.0,
        );

        return new LocationPoiEnricher($client, $radius, $maxPois, $fetchLimitMultiplier);
    }

    private function createGeocodeResult(float $lat, float $lon): GeocodeResult
    {
        return new GeocodeResult(
            provider: 'nominatim',
            providerPlaceId: 'result-' . $lat . '-' . $lon,
            lat: $lat,
            lon: $lon,
            displayName: 'Test location',
            countryCode: null,
            country: null,
            state: null,
            county: null,
            city: null,
            town: null,
            village: null,
            suburb: null,
            neighbourhood: null,
            postcode: null,
            road: null,
            houseNumber: null,
            boundingBox: null,
            category: null,
            type: null,
        );
    }

    private function createResponse(array $payload): FakeHttpResponse
    {
        return new FakeHttpResponse(200, $payload);
    }
}

/**
 * @internal simple queued HTTP client used to feed deterministic responses
 */
final class FakeHttpClient implements HttpClientInterface
{
    /**
     * @var list<FakeHttpResponse>
     */
    private array $responses;

    /**
     * @param list<FakeHttpResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->responses === []) {
            throw new BadMethodCallException('No queued HTTP response available.');
        }

        return array_shift($this->responses);
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

/**
 * @internal HTTP response stub returning static payloads
 */
final readonly class FakeHttpResponse implements ResponseInterface
{
    public function __construct(
        private int $statusCode,
        private array $payload,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR);
    }

    public function toArray(bool $throw = true): array
    {
        return $this->payload;
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        if ($type === null) {
            return [];
        }

        return null;
    }
}
