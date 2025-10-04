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
use Doctrine\ORM\EntityRepository;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Service\Geocoding\GeocodeResult;
use MagicSunday\Memories\Service\Geocoding\LocationResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocationResolverMetadataTest extends TestCase
{
    #[Test]
    public function appliesGeocodeMetadataWhenCreatingLocation(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $repository->method('findBy')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Location::class)->willReturn($repository);
        $entityManager->method('contains')->willReturn(false);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Location::class));

        $resolver = new LocationResolver($entityManager);

        $refreshedAt = new DateTimeImmutable('2025-03-15T09:45:00+00:00');
        $geocode     = new GeocodeResult(
            provider: 'nominatim',
            providerPlaceId: 'place-100',
            lat: 52.520008,
            lon: 13.404954,
            displayName: 'Berlin, Deutschland',
            countryCode: 'DE',
            country: 'Deutschland',
            state: 'Berlin',
            county: 'Berlin',
            city: 'Berlin',
            town: null,
            village: null,
            suburb: 'Mitte',
            neighbourhood: 'Nikolaiviertel',
            postcode: '10178',
            road: 'Karl-Liebknecht-Straße',
            houseNumber: '8',
            boundingBox: [52.515, 52.525, 13.4, 13.41],
            category: 'tourism',
            type: 'viewpoint',
            attribution: '© OpenStreetMap',
            licence: 'ODbL',
            refreshedAt: $refreshedAt,
            confidence: 0.92,
            accuracyRadiusMeters: 135.0,
            timezone: 'Europe/Berlin',
            osmType: 'node',
            osmId: '123456',
            wikidataId: 'Q64',
            wikipedia: 'de:Berlin',
            altNames: ['name:en' => 'Berlin', 'short' => 'BER'],
            extraTags: ['wikidata' => 'Q64', 'wikimedia_commons' => 'Category:Berlin'],
        );

        $location = $resolver->findOrCreate($geocode);

        self::assertSame('Berlin, Deutschland', $location->getDisplayName());
        self::assertSame('DE', $location->getCountryCode());
        self::assertSame('Deutschland', $location->getCountry());
        self::assertSame('Berlin', $location->getState());
        self::assertSame('Berlin', $location->getCounty());
        self::assertSame('Berlin', $location->getCity());
        self::assertSame('Mitte', $location->getSuburb());
        self::assertSame('10178', $location->getPostcode());
        self::assertSame('Karl-Liebknecht-Straße', $location->getRoad());
        self::assertSame('8', $location->getHouseNumber());
        self::assertSame('tourism', $location->getCategory());
        self::assertSame('viewpoint', $location->getType());
        self::assertSame([52.515, 52.525, 13.4, 13.41], $location->getBoundingBox());
        self::assertSame('© OpenStreetMap', $location->getAttribution());
        self::assertSame('ODbL', $location->getLicence());
        self::assertSame(0.92, $location->getConfidence());
        self::assertSame(135.0, $location->getAccuracyRadiusMeters());
        self::assertSame('Europe/Berlin', $location->getTimezone());
        self::assertSame('node', $location->getOsmType());
        self::assertSame('123456', $location->getOsmId());
        self::assertSame('Q64', $location->getWikidataId());
        self::assertSame('de:Berlin', $location->getWikipedia());
        self::assertSame(['name:en' => 'Berlin', 'short' => 'BER'], $location->getAltNames());
        self::assertSame(['wikidata' => 'Q64', 'wikimedia_commons' => 'Category:Berlin'], $location->getExtraTags());
        self::assertSame($refreshedAt, $location->getRefreshedAt());
        self::assertFalse($location->isStale());
    }
}
