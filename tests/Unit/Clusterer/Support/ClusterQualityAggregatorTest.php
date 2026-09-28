<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Support;

use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Quality\ImageQualityEstimatorInterface;
use MagicSunday\Memories\Service\Clusterer\Quality\ImageQualityScore;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ClusterQualityAggregatorTest extends TestCase
{
    #[Test]
    public function itLimitsQualityAggregationToTopMembers(): void
    {
        $mediaItems = [
            $this->makeMedia(
                id: 1,
                path: __DIR__ . '/quality-1.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(3000);
                },
            ),
            $this->makeMedia(
                id: 2,
                path: __DIR__ . '/quality-2.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(3000);
                },
            ),
            $this->makeMedia(
                id: 3,
                path: __DIR__ . '/quality-3.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(3000);
                },
            ),
        ];

        $scoresFactory = static fn (): array => [
            new ImageQualityScore(
                sharpness: 0.9,
                exposure: 0.8,
                contrast: 0.85,
                noise: 0.9,
                blockiness: 0.95,
                keyframeQuality: 0.7,
                clipping: 0.1,
            ),
            new ImageQualityScore(
                sharpness: 0.7,
                exposure: 0.75,
                contrast: 0.65,
                noise: 0.8,
                blockiness: 0.9,
                keyframeQuality: 0.6,
                clipping: 0.2,
            ),
            new ImageQualityScore(
                sharpness: 0.3,
                exposure: 0.4,
                contrast: 0.5,
                noise: 0.6,
                blockiness: 0.8,
                keyframeQuality: 0.4,
                clipping: 0.3,
            ),
        ];

        $baseline = (new ClusterQualityAggregator(
            estimator: $this->sequenceEstimator($scoresFactory()),
        ))->buildParams($mediaItems);

        $aggregator = new ClusterQualityAggregator(
            estimator: $this->sequenceEstimator($scoresFactory()),
            topK: 2,
        );

        $result = $aggregator->buildParams($mediaItems);

        self::assertTrue($result['quality_avg'] > $baseline['quality_avg']);
        self::assertTrue($result['aesthetics_score'] > $baseline['aesthetics_score']);
        self::assertEqualsWithDelta(0.8354166667, $result['quality_avg'], 1e-6);
        self::assertEqualsWithDelta(0.76, $result['aesthetics_score'], 1e-6);
        self::assertSame(1.0, $result['quality_resolution']);
        self::assertArrayHasKey('quality_members', $result);
        self::assertIsArray($result['quality_members']);
        self::assertCount(3, $result['quality_members']);

        $qualities = array_map(
            static fn (array $entry): ?float => $entry['quality'],
            $result['quality_members'],
        );

        self::assertEqualsWithDelta(0.9365, $qualities[0], 1e-4);
        self::assertEqualsWithDelta(0.7343, $qualities[1], 1e-4);
        self::assertEqualsWithDelta(0.4600, $qualities[2], 1e-4);

        $reuse = $aggregator->aggregateFromMembers($result['quality_members']);

        self::assertEqualsWithDelta($result['quality_avg'], $reuse['quality_avg'], 1e-6);
        self::assertEqualsWithDelta($result['aesthetics_score'], $reuse['aesthetics_score'], 1e-6);
        self::assertSame($result['quality_resolution'], $reuse['quality_resolution']);
        self::assertSame($result['quality_sharpness'], $reuse['quality_sharpness']);

        $allMembersReuse = (new ClusterQualityAggregator())->aggregateFromMembers($result['quality_members']);

        self::assertEqualsWithDelta($baseline['quality_avg'], $allMembersReuse['quality_avg'], 1e-6);
        self::assertEqualsWithDelta($baseline['aesthetics_score'], $allMembersReuse['aesthetics_score'], 1e-6);
    }

    /**
     * @param list<ImageQualityScore> $scores
     */
    private function sequenceEstimator(array $scores): ImageQualityEstimatorInterface
    {
        return new class($scores) implements ImageQualityEstimatorInterface {
            /**
             * @param list<ImageQualityScore> $scores
             */
            public function __construct(private array $scores)
            {
            }

            private int $index = 0;

            public function scoreStill(Media $media): ImageQualityScore
            {
                return $this->next();
            }

            public function scoreVideo(Media $media): ImageQualityScore
            {
                return $this->next();
            }

            private function next(): ImageQualityScore
            {
                if (!isset($this->scores[$this->index])) {
                    throw new RuntimeException('No more scores configured.');
                }

                return $this->scores[$this->index++];
            }
        };
    }
}
