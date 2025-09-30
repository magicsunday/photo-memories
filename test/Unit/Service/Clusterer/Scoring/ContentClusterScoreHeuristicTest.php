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
use MagicSunday\Memories\Service\Clusterer\Scoring\ContentClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContentClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichTracksKeywordCoverage(): void
    {
        $heuristic = new ContentClusterScoreHeuristic();

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $mediaMap = [
            1 => $this->makeMedia(
                id: 1,
                path: __DIR__ . '/content-1.jpg',
                configure: static function (Media $media): void {
                    $media->setKeywords(['Sunset', 'Beach']);
                },
            ),
            2 => $this->makeMedia(
                id: 2,
                path: __DIR__ . '/content-2.jpg',
                configure: static function (Media $media): void {
                    $media->setKeywords(['Sunset']);
                },
            ),
            3 => $this->makeMedia(
                id: 3,
                path: __DIR__ . '/content-3.jpg',
            ),
        ];

        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();
        self::assertSame(2, $params['content_keywords_unique']);
        self::assertSame(3, $params['content_keywords_total']);
        self::assertEqualsWithDelta(2 / 3, $params['content_coverage'], 1e-9);
        self::assertGreaterThan(0.0, $heuristic->score($cluster));
        self::assertSame('content', $heuristic->weightKey());
    }
}
