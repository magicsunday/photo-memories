<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\DaySummaryStage;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DensityStageTest extends TestCase
{
    #[Test]
    public function assignsDensityScoresBasedOnPhotoCounts(): void
    {
        $timezoneResolver = new TimezoneResolver('UTC');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'UTC');
        $densityStage     = new DensityStage();

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $items = [];
        $start = new DateTimeImmutable('2024-05-01 08:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->makeMediaFixture(
                200 + $i,
                sprintf('day-one-%d.jpg', $i),
                $start->add(new DateInterval('PT' . ($i * 2) . 'H')),
                52.5200,
                13.4050,
            );
        }

        $secondDay = $start->add(new DateInterval('P1D'));
        $items[]   = $this->makeMediaFixture(300, 'day-two.jpg', $secondDay, 52.5201, 13.4051);

        $initial = $initialStage->process($items, $home);
        $result  = $densityStage->process($initial, $home);

        self::assertGreaterThan($result['2024-05-02']['densityZ'], $result['2024-05-01']['densityZ']);
        self::assertNotSame(0.0, $result['2024-05-01']['densityZ']);
    }
}
