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
use MagicSunday\Memories\Service\Clusterer\Scoring\LivelinessClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class LivelinessClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function scoreFavoursMotionRichClusters(): void
    {
        $heuristic = $this->createHeuristic();

        $motionCluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3, 4],
        );

        $stillCluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [101, 102, 103, 104],
        );

        $mediaMap = [
            1 => $this->makeMedia(
                id: 1,
                path: __DIR__ . '/motion-video-1.mp4',
                configure: static function (Media $media): void {
                    $media->setIsVideo(true);
                    $media->setVideoDurationS(18.0);
                    $media->setVideoFps(60.0);
                    $media->setIsSlowMo(true);
                    $media->setVideoHasStabilization(true);
                    $media->setMotionBlurScore(0.62);
                },
            ),
            2 => $this->makeMedia(
                id: 2,
                path: __DIR__ . '/live-photo.jpg',
                configure: static function (Media $media): void {
                    $media->setLivePairChecksum('live-pair-checksum');
                    $media->setMotionBlurScore(0.50);
                },
            ),
            3 => $this->makeMedia(
                id: 3,
                path: __DIR__ . '/motion-video-2.mp4',
                configure: static function (Media $media): void {
                    $media->setIsVideo(true);
                    $media->setVideoDurationS(12.0);
                    $media->setVideoFps(48.0);
                    $media->setMotionBlurScore(0.45);
                },
            ),
            4 => $this->makeMedia(
                id: 4,
                path: __DIR__ . '/motion-photo.jpg',
                configure: static function (Media $media): void {
                    $media->setMotionBlurScore(0.58);
                },
            ),
            101 => $this->makeMedia(
                id: 101,
                path: __DIR__ . '/still-1.jpg',
                configure: static function (Media $media): void {
                    $media->setMotionBlurScore(0.08);
                },
            ),
            102 => $this->makeMedia(
                id: 102,
                path: __DIR__ . '/still-2.jpg',
                configure: static function (Media $media): void {
                    $media->setMotionBlurScore(0.05);
                },
            ),
            103 => $this->makeMedia(
                id: 103,
                path: __DIR__ . '/still-3.jpg',
                configure: static function (Media $media): void {
                    $media->setMotionBlurScore(0.12);
                },
            ),
            104 => $this->makeMedia(
                id: 104,
                path: __DIR__ . '/still-4.jpg',
                configure: static function (Media $media): void {
                    $media->setMotionBlurScore(0.06);
                },
            ),
        ];

        $heuristic->prepare([$motionCluster, $stillCluster], $mediaMap);
        $heuristic->enrich($motionCluster, $mediaMap);
        $heuristic->enrich($stillCluster, $mediaMap);

        $motionParams = $motionCluster->getParams();
        $stillParams  = $stillCluster->getParams();

        self::assertEqualsWithDelta(0.5, $motionParams['liveliness_video_share'], 1e-9);
        self::assertEqualsWithDelta(0.25, $motionParams['liveliness_live_share'], 1e-9);
        self::assertEqualsWithDelta(1.0, $motionParams['liveliness_motion_share'], 1e-9);
        self::assertGreaterThan($stillParams['liveliness'], $motionParams['liveliness']);
        self::assertGreaterThan(0.0, $motionParams['liveliness']);
        self::assertSame('liveliness', $heuristic->weightKey());
    }

    #[Test]
    public function scoreUsesPersistedMetricsWhenPresent(): void
    {
        $heuristic = $this->createHeuristic();

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'liveliness'                => 0.72,
                'liveliness_video_share'    => 0.4,
                'liveliness_live_share'     => 0.15,
                'liveliness_motion_share'   => 0.6,
                'liveliness_motion_score'   => 0.58,
                'liveliness_motion_blur_avg'=> 0.52,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $heuristic->prepare([], []);
        $heuristic->enrich($cluster, []);

        $params = $cluster->getParams();

        self::assertEqualsWithDelta(0.72, $heuristic->score($cluster), 1e-9);
        self::assertEqualsWithDelta(0.4, $params['liveliness_video_share'], 1e-9);
        self::assertEqualsWithDelta(0.15, $params['liveliness_live_share'], 1e-9);
        self::assertEqualsWithDelta(0.6, $params['liveliness_motion_share'], 1e-9);
        self::assertEqualsWithDelta(0.58, $params['liveliness_motion_score'], 1e-9);
        self::assertEqualsWithDelta(0.52, $params['liveliness_motion_blur_avg'], 1e-9);
    }

    private function createHeuristic(): LivelinessClusterScoreHeuristic
    {
        return new LivelinessClusterScoreHeuristic(
            videoShareWeight: 0.45,
            livePhotoShareWeight: 0.25,
            motionWeight: 0.30,
            videoShareTarget: 0.35,
            livePhotoShareTarget: 0.20,
            motionShareTarget: 0.45,
            motionBlurThreshold: 0.32,
            motionBlurTarget: 0.55,
            motionCoverageWeight: 0.65,
            motionVideoDurationThreshold: 8.0,
            motionVideoFpsThreshold: 45.0,
        );
    }
}
