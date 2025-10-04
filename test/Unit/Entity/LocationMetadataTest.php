<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Entity;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Location;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocationMetadataTest extends TestCase
{
    #[Test]
    public function storesMetadataFields(): void
    {
        $location = new Location('nominatim', 'place-1', 'Berlin', 52.5, 13.4, 'cell');

        $refreshedAt = new DateTimeImmutable('2025-03-15T09:30:00+00:00');
        $altNames    = ['name:en' => 'Berlin', 'short' => 'BER'];
        $extraTags   = ['wikidata' => 'Q64', 'wikipedia' => 'de:Berlin'];

        $location
            ->setAttribution('© OpenStreetMap')
            ->setLicence('ODbL')
            ->setRefreshedAt($refreshedAt)
            ->setStale(true)
            ->setConfidence(0.85)
            ->setAccuracyRadiusMeters(125.5)
            ->setTimezone('Europe/Berlin')
            ->setOsmType('node')
            ->setOsmId('12345')
            ->setWikidataId('Q64')
            ->setWikipedia('de:Berlin')
            ->setAltNames($altNames)
            ->setExtraTags($extraTags);

        self::assertSame('© OpenStreetMap', $location->getAttribution());
        self::assertSame('ODbL', $location->getLicence());
        self::assertSame($refreshedAt, $location->getRefreshedAt());
        self::assertTrue($location->isStale());
        self::assertSame(0.85, $location->getConfidence());
        self::assertSame(125.5, $location->getAccuracyRadiusMeters());
        self::assertSame('Europe/Berlin', $location->getTimezone());
        self::assertSame('node', $location->getOsmType());
        self::assertSame('12345', $location->getOsmId());
        self::assertSame('Q64', $location->getWikidataId());
        self::assertSame('de:Berlin', $location->getWikipedia());
        self::assertSame($altNames, $location->getAltNames());
        self::assertSame($extraTags, $location->getExtraTags());
    }
}
