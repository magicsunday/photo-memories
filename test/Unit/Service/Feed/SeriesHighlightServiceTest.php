<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Feed\SeriesHighlightService;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SeriesHighlightService::class)]
final class SeriesHighlightServiceTest extends TestCase
{
    #[Test]
    public function addsHighlightsForMultiYearSeries(): void
    {
        $cluster = new ClusterDraft(
            'on_this_day_over_years',
            ['years' => [2021, 2018, '2020', 2018]],
            ['lat' => 0.0, 'lon' => 0.0],
            [1, 2, 3]
        );

        $service = new SeriesHighlightService();
        $service->apply($cluster);

        $params = $cluster->getParams();

        self::assertSame([2018, 2020, 2021], $params['years']);

        $highlight = $params['series_highlights'] ?? null;
        self::assertIsArray($highlight);
        self::assertSame([2018, 2020, 2021], $highlight['jahre']);
        self::assertSame(3, $highlight['anzahl']);
        self::assertFalse($highlight['konsekutiv']);
        self::assertSame('2018, 2020 & 2021 (3 Jahre)', $highlight['beschreibung']);
    }

    #[Test]
    public function addsHighlightsForSingleYearSeries(): void
    {
        $cluster = new ClusterDraft(
            'season_over_years',
            ['years' => [2023]],
            ['lat' => 0.0, 'lon' => 0.0],
            [10, 11]
        );

        $service = new SeriesHighlightService();
        $service->apply($cluster);

        $params = $cluster->getParams();
        $highlight = $params['series_highlights'] ?? null;

        self::assertSame([2023], $params['years']);
        self::assertIsArray($highlight);
        self::assertSame('2023 (1 Jahr)', $highlight['beschreibung']);
        self::assertTrue($highlight['konsekutiv']);
    }
}
