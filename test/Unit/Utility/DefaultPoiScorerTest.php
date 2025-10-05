<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Utility;

use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\DefaultPoiScorer;
use PHPUnit\Framework\Attributes\Test;

final class DefaultPoiScorerTest extends TestCase
{
    #[Test]
    public function tourismPoiOutranksGenericAmenity(): void
    {
        $scorer = new DefaultPoiScorer();

        $tourism = [
            'name'  => 'City Museum',
            'names' => [
                'default'    => 'City Museum',
                'localized'  => [],
                'alternates' => [],
            ],
            'categoryKey'   => 'tourism',
            'categoryValue' => 'museum',
            'tags'          => [
                'tourism'  => 'museum',
                'wikidata' => 'Q1',
            ],
        ];

        $parking = [
            'name'  => 'Parking Lot',
            'names' => [
                'default'    => 'Parking Lot',
                'localized'  => [],
                'alternates' => [],
            ],
            'categoryKey'   => 'amenity',
            'categoryValue' => 'parking',
            'tags'          => [
                'amenity' => 'parking',
            ],
        ];

        $tourismScore = $scorer->score($tourism, 50.0);
        $parkingScore = $scorer->score($parking, 10.0);

        self::assertGreaterThan($parkingScore, $tourismScore);
    }

    #[Test]
    public function distancePenaltyReducesScore(): void
    {
        $scorer = new DefaultPoiScorer();

        $poi = [
            'name'  => 'City Museum',
            'names' => [
                'default'    => 'City Museum',
                'localized'  => [],
                'alternates' => [],
            ],
            'categoryKey'   => 'tourism',
            'categoryValue' => 'museum',
            'tags'          => [
                'tourism' => 'museum',
            ],
        ];

        $scoreClose  = $scorer->score($poi, 10.0);
        $scoreFar    = $scorer->score($poi, 500.0);
        $scoreRemote = $scorer->score($poi, null);

        self::assertGreaterThan($scoreFar, $scoreClose);
        self::assertSame($scoreClose, $scoreRemote);
    }
}
