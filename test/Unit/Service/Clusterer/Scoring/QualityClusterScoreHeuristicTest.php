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
use MagicSunday\Memories\Service\Clusterer\Quality\ImageQualityEstimatorInterface;
use MagicSunday\Memories\Service\Clusterer\Quality\ImageQualityRawMetrics;
use MagicSunday\Memories\Service\Clusterer\Quality\ImageQualityScore;
use MagicSunday\Memories\Service\Clusterer\Scoring\QualityClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class QualityClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichCalculatesQualityAndAesthetics(): void
    {
        $scores = [
            1 => new ImageQualityScore(
                sharpness: 0.2,
                exposure: 0.2,
                contrast: 0.2,
                noise: 0.2,
                blockiness: 0.2,
                keyframeQuality: 0.3,
                clipping: 0.15,
                rawMetrics: new ImageQualityRawMetrics(
                    laplacianVariance: 0.0004,
                    clippingShare: 0.15,
                    contrastStandardDeviation: 0.05,
                    noiseEstimate: 0.06,
                    blockinessEstimate: 0.1,
                ),
            ),
            2 => new ImageQualityScore(
                sharpness: 0.6,
                exposure: 0.6,
                contrast: 0.6,
                noise: 0.6,
                blockiness: 0.6,
                keyframeQuality: 0.6,
                clipping: 0.08,
                rawMetrics: new ImageQualityRawMetrics(
                    laplacianVariance: 0.001,
                    clippingShare: 0.08,
                    contrastStandardDeviation: 0.12,
                    noiseEstimate: 0.03,
                    blockinessEstimate: 0.05,
                ),
            ),
            3 => new ImageQualityScore(
                sharpness: 0.95,
                exposure: 0.95,
                contrast: 0.95,
                noise: 0.95,
                blockiness: 0.95,
                keyframeQuality: 0.9,
                clipping: 0.02,
                rawMetrics: new ImageQualityRawMetrics(
                    laplacianVariance: 0.003,
                    clippingShare: 0.02,
                    contrastStandardDeviation: 0.22,
                    noiseEstimate: 0.01,
                    blockinessEstimate: 0.02,
                ),
            ),
        ];

        $heuristic = new QualityClusterScoreHeuristic(new ClusterQualityAggregator(
            qualityBaselineMegapixels: 12.0,
            estimator: $this->createEstimator($scores),
        ));

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $mediaMap = [
            1 => $this->makeMedia(
                id: 1,
                path: __DIR__ . '/quality-1.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(3000);
                    $media->setHeight(2000);
                },
            ),
            2 => $this->makeMedia(
                id: 2,
                path: __DIR__ . '/quality-2.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(3000);
                },
            ),
            3 => $this->makeMedia(
                id: 3,
                path: __DIR__ . '/quality-3.jpg',
                configure: static function (Media $media): void {
                    $media->setWidth(6000);
                    $media->setHeight(4000);
                },
            ),
        ];

        $heuristic->prepare([], $mediaMap);
        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();
        self::assertEqualsWithDelta(0.4729800150829563, $params['quality_avg'], 1e-9);
        self::assertEqualsWithDelta(0.477526395173454, $params['aesthetics_score'], 1e-9);
        self::assertEqualsWithDelta(0.4729800150829563, $heuristic->score($cluster), 1e-9);
        self::assertEqualsWithDelta(0.5128205128205128, $params['quality_exposure'], 1e-9);
        self::assertEqualsWithDelta(0.47058823529411764, $params['quality_contrast'], 1e-9);
        self::assertEqualsWithDelta(0.5333333333333333, $params['quality_noise'], 1e-9);
        self::assertEqualsWithDelta(0.5416666666666666, $params['quality_blockiness'], 1e-9);
        self::assertEqualsWithDelta(0.5, $params['quality_video_keyframe'], 1e-9);
        self::assertEqualsWithDelta(0.08333333333333333, $params['quality_clipping'], 1e-9);
        self::assertEqualsWithDelta(0.4444444444444444, $params['quality_resolution'], 1e-9);
        self::assertEqualsWithDelta(0.41025641025641024, $params['quality_sharpness'], 1e-9);
        self::assertEqualsWithDelta(0.5333333333333333, $params['quality_iso'], 1e-9);
        self::assertArrayNotHasKey('quality_video_bonus', $params);
        self::assertArrayNotHasKey('quality_video_penalty', $params);
        self::assertSame('quality', $heuristic->weightKey());
    }

    #[Test]
    public function enrichKeepsPersistedQualityMetrics(): void
    {
        $heuristic = new QualityClusterScoreHeuristic(new ClusterQualityAggregator(12.0));

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'quality_avg'        => 0.67,
                'aesthetics_score'   => 0.7,
                'quality_resolution' => 0.8,
                'quality_sharpness'  => 0.6,
                'quality_exposure'   => 0.62,
                'quality_contrast'   => 0.64,
                'quality_noise'      => 0.52,
                'quality_blockiness' => 0.68,
                'quality_video_keyframe' => 0.7,
                'quality_video_bonus'    => 0.25,
                'quality_video_penalty'  => 0.12,
                'quality_clipping'       => 0.08,
                'quality_iso'            => 0.3,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1],
        );

        $heuristic->prepare([], []);
        $heuristic->enrich($cluster, []);

        $params = $cluster->getParams();

        self::assertEqualsWithDelta(0.67, $params['quality_avg'], 1e-9);
        self::assertEqualsWithDelta(0.7, $params['aesthetics_score'], 1e-9);
        self::assertEqualsWithDelta(0.8, $params['quality_resolution'], 1e-9);
        self::assertEqualsWithDelta(0.6, $params['quality_sharpness'], 1e-9);
        self::assertEqualsWithDelta(0.62, $params['quality_exposure'], 1e-9);
        self::assertEqualsWithDelta(0.64, $params['quality_contrast'], 1e-9);
        self::assertEqualsWithDelta(0.52, $params['quality_noise'], 1e-9);
        self::assertEqualsWithDelta(0.68, $params['quality_blockiness'], 1e-9);
        self::assertEqualsWithDelta(0.7, $params['quality_video_keyframe'], 1e-9);
        self::assertEqualsWithDelta(0.25, $params['quality_video_bonus'], 1e-9);
        self::assertEqualsWithDelta(0.12, $params['quality_video_penalty'], 1e-9);
        self::assertEqualsWithDelta(0.08, $params['quality_clipping'], 1e-9);
        self::assertEqualsWithDelta(0.3, $params['quality_iso'], 1e-9);
        self::assertEqualsWithDelta(0.67, $heuristic->score($cluster), 1e-9);
    }

    #[Test]
    public function enrichCalculatesVideoAdjustments(): void
    {
        $videoScore = new ImageQualityScore(
            sharpness: 0.7,
            exposure: 0.65,
            contrast: 0.6,
            noise: 0.55,
            blockiness: 0.8,
            keyframeQuality: 0.75,
            clipping: 0.1,
            videoBonus: 0.4,
            videoPenalty: 0.2,
            rawMetrics: new ImageQualityRawMetrics(
                laplacianVariance: 0.0015,
                clippingShare: 0.1,
                contrastStandardDeviation: 0.18,
                noiseEstimate: 0.025,
                blockinessEstimate: 0.04,
            ),
        );

        $aggregator = new ClusterQualityAggregator(
            qualityBaselineMegapixels: 12.0,
            estimator: $this->createEstimator([
                3 => $videoScore,
            ]),
        );

        $heuristic = new QualityClusterScoreHeuristic($aggregator);

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [3],
        );

        $mediaMap = [
            3 => $this->makeMedia(
                id: 3,
                path: __DIR__ . '/video-quality.mp4',
                configure: static function (Media $media): void {
                    $media->setWidth(3840);
                    $media->setHeight(2160);
                    $media->setIsVideo(true);
                    $media->setVideoDurationS(42.0);
                    $media->setVideoFps(30.0);
                },
            ),
        ];

        $heuristic->prepare([], $mediaMap);
        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();

        $resolution = (((float) 3840 * 2160) / 1_000_000.0) / 12.0;
        $weights    = ClusterQualityAggregator::DEFAULT_QUALITY_WEIGHTS;
        $weighted   = (
            ($weights['resolution'] * $resolution)
            + ($weights['sharpness'] * 0.7)
            + ($weights['exposure'] * 0.65)
            + ($weights['contrast'] * 0.6)
            + ($weights['noise'] * 0.55)
            + ($weights['blockiness'] * 0.8)
            + ($weights['keyframe'] * 0.75)
        );

        $expectedQuality = $weighted
            + (ClusterQualityAggregator::DEFAULT_VIDEO_BONUS_WEIGHT * 0.4)
            - (ClusterQualityAggregator::DEFAULT_VIDEO_PENALTY_WEIGHT * 0.2);
        $expectedQuality = max(0.0, min(1.0, $expectedQuality));

        self::assertEqualsWithDelta(0.6425, $params['aesthetics_score'], 1e-6);
        self::assertEqualsWithDelta($resolution, $params['quality_resolution'], 1e-6);
        self::assertEqualsWithDelta(0.7, $params['quality_sharpness'], 1e-6);
        self::assertEqualsWithDelta(0.65, $params['quality_exposure'], 1e-6);
        self::assertEqualsWithDelta(0.6, $params['quality_contrast'], 1e-6);
        self::assertEqualsWithDelta(0.55, $params['quality_noise'], 1e-6);
        self::assertEqualsWithDelta(0.8, $params['quality_blockiness'], 1e-6);
        self::assertEqualsWithDelta(0.75, $params['quality_video_keyframe'], 1e-6);
        self::assertEqualsWithDelta(0.4, $params['quality_video_bonus'], 1e-6);
        self::assertEqualsWithDelta(0.2, $params['quality_video_penalty'], 1e-6);
        self::assertEqualsWithDelta(0.1, $params['quality_clipping'], 1e-6);
        self::assertEqualsWithDelta(0.55, $params['quality_iso'], 1e-6);
        self::assertEqualsWithDelta($expectedQuality, $params['quality_avg'], 1e-6);
        self::assertEqualsWithDelta($expectedQuality, $heuristic->score($cluster), 1e-6);
    }

    /**
     * @param array<int, ImageQualityScore> $scores
     */
    private function createEstimator(array $scores): ImageQualityEstimatorInterface
    {
        return new class($scores) implements ImageQualityEstimatorInterface {
            /**
             * @param array<int, ImageQualityScore> $scores
             */
            public function __construct(private readonly array $scores)
            {
            }

            public function scoreStill(Media $media): ImageQualityScore
            {
                return $this->resolve($media);
            }

            public function scoreVideo(Media $media): ImageQualityScore
            {
                return $this->resolve($media);
            }

            private function resolve(Media $media): ImageQualityScore
            {
                return $this->scores[$media->getId()] ?? new ImageQualityScore(
                    sharpness: 0.5,
                    exposure: 0.5,
                    contrast: 0.5,
                    noise: 0.5,
                    blockiness: 0.5,
                    keyframeQuality: 0.5,
                    clipping: 0.0,
                    videoBonus: 0.0,
                    videoPenalty: 0.0,
                );
            }
        };
    }
}
