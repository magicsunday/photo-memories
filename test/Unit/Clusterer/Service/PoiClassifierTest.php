<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Service;

use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PoiClassifierTest extends TestCase
{
    #[Test]
    public function marksLocationAsPoiSampleWhenPoisAvailable(): void
    {
        $classifier = new PoiClassifier();
        $location   = $this->makeLocation('poi-sample', 'Sample', 47.0, 10.0);
        $location->setPois([
            [
                'categoryKey'   => 'tourism',
                'categoryValue' => 'zoo',
            ],
        ]);

        self::assertTrue($classifier->isPoiSample($location));
    }

    #[Test]
    public function detectsTourismPoiFromTags(): void
    {
        $classifier = new PoiClassifier();
        $location   = $this->makeLocation('tourism', 'Art Museum', 48.1, 11.5);
        $location->setPois([
            [
                'categoryKey'   => 'amenity',
                'categoryValue' => 'arts_centre',
                'tags'          => [
                    'tourism' => 'museum',
                ],
            ],
        ]);

        self::assertTrue($classifier->isTourismPoi($location));
    }

    #[Test]
    public function detectsTransportPoiFromCategory(): void
    {
        $classifier = new PoiClassifier();
        $location   = $this->makeLocation('transport', 'Central Station', 52.5, 13.4);
        $location->setCategory('railway_station');

        self::assertTrue($classifier->isTransportPoi($location));
    }
}
