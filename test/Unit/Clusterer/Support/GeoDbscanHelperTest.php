<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Support;

use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class GeoDbscanHelperTest extends TestCase
{
    #[Test]
    public function clustersTightGroupAndIdentifiesNoise(): void
    {
        $helper = new GeoDbscanHelper();

        $media   = [];
        $media[] = $this->makeMediaFixture(1, 'cluster-a.jpg', '2024-07-01 10:00:00', 48.8566, 2.3522);
        $media[] = $this->makeMediaFixture(2, 'cluster-b.jpg', '2024-07-01 10:02:00', 48.8567, 2.3523);
        $media[] = $this->makeMediaFixture(3, 'cluster-c.jpg', '2024-07-01 10:05:00', 48.85665, 2.35225);
        $media[] = $this->makeMediaFixture(10, 'noise-distant.jpg', '2024-07-01 12:00:00', 52.5200, 13.4050);
        $media[] = $this->makeMediaFixture(11, 'noise-solitary.jpg', '2024-07-01 14:00:00', 34.0522, -118.2437);

        $result = $helper->clusterMedia($media, 0.2, 3);

        self::assertCount(1, $result['clusters']);

        $clusterIds = array_map(static fn (Media $item): int => $item->getId(), $result['clusters'][0]);
        sort($clusterIds);

        self::assertSame([1, 2, 3], $clusterIds);

        $noiseIds = array_map(static fn (Media $item): int => $item->getId(), $result['noise']);
        sort($noiseIds);

        self::assertSame([10, 11], $noiseIds);
    }

    #[Test]
    public function returnsEmptyResultForEmptyInput(): void
    {
        $helper = new GeoDbscanHelper();

        $result = $helper->clusterMedia([]);

        self::assertSame([], $result['clusters']);
        self::assertSame([], $result['noise']);
    }

    #[Test]
    public function treatsAllMediaAsNoiseWhenBelowMinSamples(): void
    {
        $helper = new GeoDbscanHelper();

        $media   = [];
        $media[] = $this->makeMediaFixture(20, 'pair-a.jpg', '2024-07-02 09:00:00', 51.5007, -0.1246);
        $media[] = $this->makeMediaFixture(21, 'pair-b.jpg', '2024-07-02 09:05:00', 51.5008, -0.1247);

        $result = $helper->clusterMedia($media, 0.2, 4);

        self::assertSame([], $result['clusters']);

        $noiseIds = array_map(static fn (Media $item): int => $item->getId(), $result['noise']);
        sort($noiseIds);

        self::assertSame([20, 21], $noiseIds);
    }

    #[Test]
    public function skipsMediaWithoutGpsCoordinates(): void
    {
        $helper = new GeoDbscanHelper();

        $media   = [];
        $media[] = $this->makeMediaFixture(30, 'no-gps-a.jpg', '2024-07-03 11:00:00');
        $media[] = $this->makeMediaFixture(31, 'no-gps-b.jpg', '2024-07-03 11:05:00', 40.4168, null);
        $media[] = $this->makeMediaFixture(32, 'no-gps-c.jpg', '2024-07-03 11:10:00', null, -3.7038);

        $result = $helper->clusterMedia($media, 0.2, 3);

        self::assertSame([], $result['clusters']);
        self::assertSame([], $result['noise']);
    }
}
