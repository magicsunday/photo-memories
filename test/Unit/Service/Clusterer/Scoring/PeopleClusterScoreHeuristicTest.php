<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\PeopleClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PeopleClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichCountsUniquePeopleAndCoverage(): void
    {
        $heuristic = new PeopleClusterScoreHeuristic();

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $mediaMap = [
            1 => $this->makeMedia(
                id: 1,
                path: __DIR__ . '/people-1.jpg',
                configure: static function (Media $media): void {
                    $media->setPersons(['Alice', 'Bob']);
                },
            ),
            2 => $this->makeMedia(
                id: 2,
                path: __DIR__ . '/people-2.jpg',
                configure: static function (Media $media): void {
                    $media->setPersons(['Alice']);
                },
            ),
            3 => $this->makeMedia(
                id: 3,
                path: __DIR__ . '/people-3.jpg',
            ),
        ];

        $heuristic->prepare([], $mediaMap);
        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();
        self::assertSame(3, $params['people_count']);
        self::assertSame(2, $params['people_unique']);
        self::assertEqualsWithDelta(2 / 3, $params['people_coverage'], 1e-9);
        self::assertGreaterThan(0.0, $heuristic->score($cluster));
        self::assertSame('people', $heuristic->weightKey());
    }
}
