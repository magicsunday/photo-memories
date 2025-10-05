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
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\QualityClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class QualityClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichCalculatesQualityAndAesthetics(): void
    {
        $heuristic = new QualityClusterScoreHeuristic(new ClusterQualityAggregator(12.0));

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $mediaMap = [
            1 => $this->makeMedia(
                id: 1,
                path: __DIR__ . '/quality-1.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(3000);
                    $media->setSharpness(1.0);
                    $media->setIso(50);
                    $media->setBrightness(0.55);
                    $media->setContrast(1.0);
                    $media->setEntropy(1.0);
                    $media->setColorfulness(1.0);
                },
            ),
            2 => $this->makeMedia(
                id: 2,
                path: __DIR__ . '/quality-2.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(3000);
                    $media->setSharpness(1.0);
                    $media->setIso(50);
                    $media->setBrightness(0.55);
                    $media->setContrast(1.0);
                    $media->setEntropy(1.0);
                    $media->setColorfulness(1.0);
                },
            ),
        ];

        $heuristic->prepare([], $mediaMap);
        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();
        self::assertEqualsWithDelta(1.0, $params['quality_avg'], 1e-9);
        self::assertEqualsWithDelta(1.0, $params['aesthetics_score'], 1e-9);
        self::assertEqualsWithDelta(1.0, $heuristic->score($cluster), 1e-9);
        self::assertSame('quality', $heuristic->weightKey());
    }
}
