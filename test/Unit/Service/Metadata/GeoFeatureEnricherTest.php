<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\GeoFeatureEnricher;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\MediaMath;
use MagicSunday\Memories\Utility\S2CellId;
use PHPUnit\Framework\Attributes\Test;

use function floor;
use function hash;
use function sprintf;

final class GeoFeatureEnricherTest extends TestCase
{
    private const float HOME_LAT = 48.137154;

    private const float HOME_LON = 11.576124;

    private const float HOME_RADIUS_KM = 15.0;

    private const string HOME_VERSION = 'v1';

    private const float CELL_DEGREES = 0.01;

    #[Test]
    public function recomputesMetricsWhenConfigurationChanges(): void
    {
        $media = $this->makeMedia(
            id: 1,
            path: '/fixtures/outdated-home.jpg',
            lat: 48.137154,
            lon: 11.576124,
            configure: static function (Media $media): void {
                $media->setGeoCell8('legacy-cell');
                $media->setDistanceKmFromHome(42.0);
                $media->setHomeConfigHash('legacy');
            },
        );

        $enricher = $this->createEnricher();
        $result   = $enricher->extract($media->getPath(), $media);

        $expectedHash     = $this->computeHomeConfigHash();
        $expectedCell     = $this->expectedCell(48.137154, 11.576124);
        $expectedDistance = $this->expectedDistanceKm(48.137154, 11.576124);
        $expectedGeohash7 = 'u281z7j';
        $expectedGeohash5 = 'u281z';
        $expectedS2Cell   = S2CellId::tokenFromDegrees(48.137154, 11.576124, 12);

        self::assertSame($expectedHash, $result->getHomeConfigHash());
        self::assertSame($expectedCell, $result->getGeoCell8());
        self::assertSame($expectedDistance, $result->getDistanceKmFromHome());
        self::assertSame($expectedGeohash7, $result->getGeohash7());
        self::assertSame($expectedGeohash5, $result->getGeohash5());
        self::assertSame($expectedS2Cell, $result->getS2CellId());
        self::assertTrue($result->needsGeocode());
    }

    #[Test]
    public function skipsRecomputeWhenHashMatches(): void
    {
        $expectedHash = $this->computeHomeConfigHash();

        $media = $this->makeMedia(
            id: 2,
            path: '/fixtures/current-home.jpg',
            lat: 48.200321,
            lon: 11.601234,
            configure: static function (Media $media) use ($expectedHash): void {
                $media->setGeoCell8('custom-cell');
                $media->setDistanceKmFromHome(12.5);
                $media->setHomeConfigHash($expectedHash);
                $media->setGeohash7('legacy7');
                $media->setGeohash5('legacy5');
            },
        );

        $enricher = $this->createEnricher();
        $result   = $enricher->extract($media->getPath(), $media);

        $expectedGeohash7 = 'u284pyx';
        $expectedGeohash5 = 'u284p';
        $expectedS2Cell   = S2CellId::tokenFromDegrees(48.200321, 11.601234, 12);

        self::assertSame($expectedHash, $result->getHomeConfigHash());
        self::assertSame('custom-cell', $result->getGeoCell8());
        self::assertSame(12.5, $result->getDistanceKmFromHome());
        self::assertSame($expectedGeohash7, $result->getGeohash7());
        self::assertSame($expectedGeohash5, $result->getGeohash5());
        self::assertSame($expectedS2Cell, $result->getS2CellId());
        self::assertTrue($result->needsGeocode());
    }

    #[Test]
    public function recomputesMissingMetricsWhenHashMatches(): void
    {
        $expectedHash = $this->computeHomeConfigHash();
        $lat          = 48.190123;
        $lon          = 11.602345;

        $media = $this->makeMedia(
            id: 3,
            path: '/fixtures/missing-metrics.jpg',
            lat: $lat,
            lon: $lon,
            configure: static function (Media $media) use ($expectedHash): void {
                $media->setGeoCell8(null);
                $media->setDistanceKmFromHome(null);
                $media->setHomeConfigHash($expectedHash);
            },
        );

        $enricher = $this->createEnricher();
        $result   = $enricher->extract($media->getPath(), $media);

        $expectedCell     = $this->expectedCell($lat, $lon);
        $expectedDistance = $this->expectedDistanceKm($lat, $lon);
        $expectedGeohash7 = 'u2860h8';
        $expectedGeohash5 = 'u2860';
        $expectedS2Cell   = S2CellId::tokenFromDegrees($lat, $lon, 12);

        self::assertSame($expectedHash, $result->getHomeConfigHash());
        self::assertSame($expectedCell, $result->getGeoCell8());
        self::assertSame($expectedDistance, $result->getDistanceKmFromHome());
        self::assertSame($expectedGeohash7, $result->getGeohash7());
        self::assertSame($expectedGeohash5, $result->getGeohash5());
        self::assertSame($expectedS2Cell, $result->getS2CellId());
        self::assertTrue($result->needsGeocode());
    }

    private function createEnricher(): GeoFeatureEnricher
    {
        return new GeoFeatureEnricher(
            self::HOME_LAT,
            self::HOME_LON,
            self::HOME_RADIUS_KM,
            self::HOME_VERSION,
            self::CELL_DEGREES,
            12,
        );
    }

    private function computeHomeConfigHash(): string
    {
        return hash(
            'sha256',
            sprintf(
                '%.8f|%.8f|%.8f|%s',
                self::HOME_LAT,
                self::HOME_LON,
                self::HOME_RADIUS_KM,
                self::HOME_VERSION,
            ),
        );
    }

    private function expectedCell(float $lat, float $lon): string
    {
        $rlat = self::CELL_DEGREES * floor($lat / self::CELL_DEGREES);
        $rlon = self::CELL_DEGREES * floor($lon / self::CELL_DEGREES);

        return sprintf('%.4f,%.4f', $rlat, $rlon);
    }

    private function expectedDistanceKm(float $lat, float $lon): float
    {
        $distanceM = MediaMath::haversineDistanceInMeters(self::HOME_LAT, self::HOME_LON, $lat, $lon);

        return $distanceM / 1000.0;
    }
}
