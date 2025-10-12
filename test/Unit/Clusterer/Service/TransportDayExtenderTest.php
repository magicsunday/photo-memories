<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Service;

use MagicSunday\Memories\Clusterer\Service\TransportDayExtender;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class TransportDayExtenderTest extends TestCase
{
    #[Test]
    public function doesNotBridgeMoreThanAllowedLeanDays(): void
    {
        $extender = new TransportDayExtender(
            transitRatioThreshold: 0.6,
            transitSpeedThreshold: 90.0,
            leanPhotoThreshold: 2,
            maxLeanBridgeDays: 1,
            minLeanBridgeDistanceKm: 60.0,
        );

        $run         = ['2024-07-02'];
        $orderedKeys = ['2024-07-01', '2024-07-02'];
        $indexByKey  = ['2024-07-01' => 0, '2024-07-02' => 1];
        $days        = [
            '2024-07-01' => $this->makeLeanSummary(52.5, 13.4),
            '2024-07-02' => $this->makeLeanSummary(48.1, 11.5),
        ];

        $extended = $extender->extend($run, $orderedKeys, $indexByKey, $days);

        self::assertSame($run, $extended);
    }

    #[Test]
    public function bridgesLeanDayWhenInterDayDistanceIsHigh(): void
    {
        $extender = new TransportDayExtender(
            transitRatioThreshold: 0.6,
            transitSpeedThreshold: 90.0,
            leanPhotoThreshold: 2,
            maxLeanBridgeDays: 1,
            minLeanBridgeDistanceKm: 60.0,
        );

        $run         = ['2024-07-02'];
        $orderedKeys = ['2024-07-01', '2024-07-02'];
        $indexByKey  = ['2024-07-01' => 0, '2024-07-02' => 1];
        $days        = [
            '2024-07-01' => $this->makeLeanSummary(40.4168, -3.7038),
            '2024-07-02' => $this->makeAnchorSummary(48.2082, 16.3738),
        ];

        $extended = $extender->extend($run, $orderedKeys, $indexByKey, $days);

        self::assertSame(['2024-07-01', '2024-07-02'], $extended);
    }

    #[Test]
    public function skipsLeanDayWithoutTransitSignalsOrDistance(): void
    {
        $extender = new TransportDayExtender(
            transitRatioThreshold: 0.6,
            transitSpeedThreshold: 90.0,
            leanPhotoThreshold: 2,
            maxLeanBridgeDays: 1,
            minLeanBridgeDistanceKm: 60.0,
        );

        $run         = ['2024-07-02'];
        $orderedKeys = ['2024-07-01', '2024-07-02'];
        $indexByKey  = ['2024-07-01' => 0, '2024-07-02' => 1];
        $days        = [
            '2024-07-01' => $this->makeLeanSummary(52.52, 13.405),
            '2024-07-02' => $this->makeAnchorSummary(52.6, 13.5),
        ];

        $extended = $extender->extend($run, $orderedKeys, $indexByKey, $days);

        self::assertSame($run, $extended);
    }

    private function makeLeanSummary(float $lat, float $lon): array
    {
        return [
            'dominantStaypoints' => [],
            'photoCount'         => 1,
            'hasAirportPoi'      => false,
            'hasHighSpeedTransit'=> false,
            'transitRatio'       => 0.0,
            'avgSpeedKmh'        => 0.0,
            'maxSpeedKmh'        => 0.0,
            'baseLocation'       => [
                'lat' => $lat,
                'lon' => $lon,
            ],
            'gpsMembers'         => [],
            'isSynthetic'        => false,
        ];
    }

    private function makeAnchorSummary(float $lat, float $lon): array
    {
        return [
            'dominantStaypoints' => [[
                'lat'          => $lat,
                'lon'          => $lon,
                'start'        => 0,
                'end'          => 0,
                'dwellSeconds' => 3600,
                'memberCount'  => 5,
            ]],
            'photoCount'         => 5,
            'hasAirportPoi'      => false,
            'hasHighSpeedTransit'=> false,
            'transitRatio'       => 0.0,
            'avgSpeedKmh'        => 20.0,
            'maxSpeedKmh'        => 25.0,
            'baseLocation'       => [
                'lat' => $lat,
                'lon' => $lon,
            ],
            'gpsMembers'         => [],
            'isSynthetic'        => false,
        ];
    }
}
