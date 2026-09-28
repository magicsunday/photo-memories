<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Service\Geocoding\DefaultLocationRefreshProcessor;
use MagicSunday\Memories\Service\Geocoding\GeocodeResult;
use MagicSunday\Memories\Service\Geocoding\LocationPoiEnricher;
use MagicSunday\Memories\Service\Geocoding\LocationResolver;
use MagicSunday\Memories\Service\Geocoding\OverpassClient;
use MagicSunday\Memories\Service\Geocoding\OverpassQueryBuilderInterface;
use MagicSunday\Memories\Service\Geocoding\OverpassResponseParserInterface;
use MagicSunday\Memories\Service\Geocoding\ReverseGeocoderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DefaultLocationRefreshProcessorTest extends TestCase
{
    #[Test]
    public function refreshesMetadataAndCountsWork(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $reverseGeocoder = $this->createMock(ReverseGeocoderInterface::class);
        $reverseGeocoder->expects(self::once())
            ->method('reverse')
            ->with(52.5, 13.4, 'de')
            ->willReturn(new GeocodeResult(
                provider: 'nominatim',
                providerPlaceId: '123',
                lat: 52.5,
                lon: 13.4,
                displayName: 'Berlin, Deutschland',
                countryCode: 'de',
                country: 'Deutschland',
                state: 'Berlin',
                county: 'Berlin',
                city: 'Berlin',
                town: null,
                village: null,
                suburb: 'Mitte',
                neighbourhood: null,
                postcode: '10117',
                road: 'Unter den Linden',
                houseNumber: '77',
                boundingBox: [52.4, 52.6, 13.3, 13.5],
                category: 'place',
                type: 'city',
                attribution: 'OpenStreetMap contributors',
                licence: 'ODbL',
                refreshedAt: new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
                confidence: 0.85,
                accuracyRadiusMeters: 50.0,
                timezone: 'Europe/Berlin',
                osmType: 'relation',
                osmId: '62422',
                wikidataId: 'Q64',
                wikipedia: 'de:Berlin',
                altNames: ['en' => 'Berlin'],
                extraTags: ['population' => '3664088'],
            ));

        $location = new Location('nominatim', '123', 'Berlin', 52.5, 13.4, 'cell-1');
        $location->setCountry('Germany');

        $resolver = $this->createResolver($entityManager);

        $processor = new DefaultLocationRefreshProcessor(
            $entityManager,
            $reverseGeocoder,
            $resolver,
            'de',
            10,
        );

        $summary = $processor->process([$location], false, false, new NullOutput());

        self::assertSame('Berlin, Deutschland', $location->getDisplayName());
        self::assertSame('DE', $location->getCountryCode());
        self::assertSame('Deutschland', $location->getCountry());
        self::assertSame('Mitte', $location->getSuburb());
        self::assertSame('Unter den Linden', $location->getRoad());
        self::assertSame('77', $location->getHouseNumber());
        self::assertSame('Europe/Berlin', $location->getTimezone());
        self::assertSame('relation', $location->getOsmType());
        self::assertSame('62422', $location->getOsmId());
        self::assertSame('Q64', $location->getWikidataId());
        self::assertSame('de:Berlin', $location->getWikipedia());
        self::assertSame(['en' => 'Berlin'], $location->getAltNames());
        self::assertSame(['population' => '3664088'], $location->getExtraTags());

        self::assertSame(1, $summary->getProcessed());
        self::assertSame(1, $summary->getMetadataUpdated());
        self::assertSame(0, $summary->getPoisUpdated());
        self::assertSame(1, $summary->getGeocodeCalls());
        self::assertSame(0, $summary->getPoiNetworkCalls());
    }

    #[Test]
    public function honoursDryRunWithoutFlushing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $reverseGeocoder = $this->createMock(ReverseGeocoderInterface::class);
        $reverseGeocoder->expects(self::once())
            ->method('reverse')
            ->willReturn(new GeocodeResult(
                provider: 'nominatim',
                providerPlaceId: '42',
                lat: 48.1,
                lon: 11.5,
                displayName: 'München, Deutschland',
                countryCode: 'de',
                country: 'Deutschland',
                state: 'Bayern',
                county: 'Oberbayern',
                city: 'München',
                town: null,
                village: null,
                suburb: null,
                neighbourhood: null,
                postcode: '80331',
                road: null,
                houseNumber: null,
                boundingBox: null,
                category: 'place',
                type: 'city',
                attribution: null,
                licence: null,
                refreshedAt: null,
                confidence: null,
                accuracyRadiusMeters: null,
                timezone: null,
                osmType: null,
                osmId: null,
                wikidataId: null,
                wikipedia: null,
                altNames: null,
                extraTags: null,
            ));

        $location = new Location('nominatim', '42', 'München', 48.1, 11.5, 'cell-2');
        $location->setPois([
            ['name' => 'Marienplatz'],
        ]);

        $resolver = $this->createResolver($entityManager);

        $processor = new DefaultLocationRefreshProcessor(
            $entityManager,
            $reverseGeocoder,
            $resolver,
            'de',
            10,
        );

        $summary = $processor->process([$location], true, true, new NullOutput());

        self::assertNull($location->getPois());
        self::assertSame(1, $summary->getProcessed());
        self::assertSame(1, $summary->getMetadataUpdated());
        self::assertSame(1, $summary->getPoisUpdated());
        self::assertSame(1, $summary->getGeocodeCalls());
    }

    private function createResolver(EntityManagerInterface $entityManager): LocationResolver
    {
        $httpClient     = $this->createMock(HttpClientInterface::class);
        $queryBuilder   = $this->createMock(OverpassQueryBuilderInterface::class);
        $responseParser = $this->createMock(OverpassResponseParserInterface::class);

        $overpassClient = new OverpassClient($httpClient, $queryBuilder, $responseParser);
        $poiEnricher    = new LocationPoiEnricher(
            $overpassClient,
            radiusMeters: 250,
            maxPois: 0,
            fetchLimitMultiplier: 1.0,
        );

        return new LocationResolver($entityManager, 0.01, $poiEnricher);
    }
}
