<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Feed\DefaultCoverPicker;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

final class DefaultCoverPickerTest extends TestCase
{
    #[Test]
    public function rotatedPortraitMatchesUprightPortraitScore(): void
    {
        $picker = new DefaultCoverPicker();

        $uprightPortrait = $this->makeMedia(
            id: 101,
            path: '/fixtures/feed/upright.jpg',
            takenAt: '2024-08-01T12:00:00+00:00',
            size: 5_000_000,
            configure: static function (Media $media): void {
                $media->setWidth(3024);
                $media->setHeight(4032);
                $media->setOrientation(1);
                $media->setNeedsRotation(false);
                $media->setThumbnails(['default' => '/thumbs/upright.jpg']);
            },
        );

        $rotatedPortrait = $this->makeMedia(
            id: 102,
            path: '/fixtures/feed/rotated.jpg',
            takenAt: '2024-08-01T12:00:00+00:00',
            size: 5_000_000,
            configure: static function (Media $media): void {
                $media->setWidth(4032);
                $media->setHeight(3024);
                $media->setOrientation(6);
                $media->setNeedsRotation(true);
                $media->setThumbnails(['default' => '/thumbs/rotated.jpg']);
            },
        );

        $buildContext = new ReflectionMethod(DefaultCoverPicker::class, 'buildContext');
        $buildContext->setAccessible(true);

        $context = $buildContext->invoke($picker, [$uprightPortrait, $rotatedPortrait], []);

        $score = new ReflectionMethod(DefaultCoverPicker::class, 'score');
        $score->setAccessible(true);

        $uprightScore = $score->invoke($picker, $uprightPortrait, $context);
        $rotatedScore = $score->invoke($picker, $rotatedPortrait, $context);

        self::assertEqualsWithDelta($uprightScore, $rotatedScore, 1e-6);
    }

    #[Test]
    public function itPrefersMemberWithHigherQualityTelemetry(): void
    {
        $picker = new DefaultCoverPicker();

        $lowQuality = $this->makeMedia(
            id: 201,
            path: '/fixtures/feed/low-quality.jpg',
            takenAt: '2024-05-12T09:00:00+00:00',
            size: 3_000_000,
            configure: static function (Media $media): void {
                $media->setWidth(2000);
                $media->setHeight(1500);
                $media->setQualityScore(0.35);
            },
        );

        $highQuality = $this->makeMedia(
            id: 202,
            path: '/fixtures/feed/high-quality.jpg',
            takenAt: '2024-05-12T09:05:00+00:00',
            size: 6_500_000,
            configure: static function (Media $media): void {
                $media->setWidth(4032);
                $media->setHeight(3024);
                $media->setQualityScore(0.8);
                $media->setContrast(0.7);
                $media->setEntropy(0.65);
            },
        );

        $clusterParams = [
            'member_quality' => [
                'summary' => [
                    'quality_avg'    => 0.6,
                    'aesthetics_avg' => 0.55,
                ],
                'weights' => [
                    'quality'    => 0.7,
                    'aesthetics' => 0.3,
                    'duplicates' => [
                        'phash' => 0.4,
                        'dhash' => 0.25,
                    ],
                ],
                'members' => [
                    (string) $lowQuality->getId()  => [
                        'quality'    => 0.35,
                        'aesthetics' => 0.32,
                    ],
                    (string) $highQuality->getId() => [
                        'quality'    => 0.85,
                        'aesthetics' => 0.78,
                    ],
                ],
            ],
        ];

        $result = $picker->pickCover([$lowQuality, $highQuality], $clusterParams);

        self::assertSame($highQuality, $result);
    }

    #[Test]
    public function itBoostsFacesWhenPeopleCoverageIsHigh(): void
    {
        $picker = new DefaultCoverPicker();

        $groupPhoto = $this->makeMedia(
            id: 301,
            path: '/fixtures/feed/group.jpg',
            takenAt: '2024-06-18T18:00:00+00:00',
            size: 5_200_000,
            configure: static function (Media $media): void {
                $media->setWidth(3840);
                $media->setHeight(2560);
                $media->setHasFaces(true);
                $media->setFacesCount(3);
                $media->setFeatures([
                    'vision' => [
                        'face_coverage'             => 0.68,
                        'primary_pose'              => 'smiling',
                        'primary_pose_confidence'   => 0.9,
                    ],
                ]);
            },
        );

        $landscape = $this->makeMedia(
            id: 302,
            path: '/fixtures/feed/landscape.jpg',
            takenAt: '2024-06-18T18:05:00+00:00',
            size: 5_200_000,
            configure: static function (Media $media): void {
                $media->setWidth(5120);
                $media->setHeight(2880);
            },
        );

        $clusterParams = [
            'people_face_coverage' => 0.6,
            'member_quality'       => [
                'weights' => [
                    'quality'    => 0.6,
                    'aesthetics' => 0.4,
                    'duplicates' => [
                        'phash' => 0.35,
                        'dhash' => 0.25,
                    ],
                ],
            ],
        ];

        $result = $picker->pickCover([$landscape, $groupPhoto], $clusterParams);

        self::assertSame($groupPhoto, $result);
    }

    #[Test]
    public function itPenalisesNearDuplicateFingerprints(): void
    {
        $picker = new DefaultCoverPicker();

        $original = $this->makeMedia(
            id: 401,
            path: '/fixtures/feed/original.jpg',
            takenAt: '2024-07-01T10:00:00+00:00',
            size: 4_500_000,
            configure: static function (Media $media): void {
                $media->setWidth(4000);
                $media->setHeight(3000);
                $media->setPhash('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
                $media->setDhash('bbbbbbbbbbbbbbbb');
            },
        );

        $duplicate = $this->makeMedia(
            id: 402,
            path: '/fixtures/feed/duplicate.jpg',
            takenAt: '2024-07-01T10:02:00+00:00',
            size: 4_400_000,
            configure: static function (Media $media): void {
                $media->setWidth(3980);
                $media->setHeight(2980);
                $media->setPhash('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
                $media->setDhash('bbbbbbbbbbbbbbbb');
            },
        );

        $unique = $this->makeMedia(
            id: 403,
            path: '/fixtures/feed/unique.jpg',
            takenAt: '2024-07-01T10:04:00+00:00',
            size: 4_800_000,
            configure: static function (Media $media): void {
                $media->setWidth(4200);
                $media->setHeight(2800);
                $media->setPhash('cccccccccccccccccccccccccccccccc');
                $media->setDhash('dddddddddddddddd');
            },
        );

        $clusterParams = [
            'member_quality' => [
                'weights' => [
                    'quality'    => 0.6,
                    'aesthetics' => 0.4,
                    'duplicates' => [
                        'phash' => 0.6,
                        'dhash' => 0.4,
                    ],
                ],
                'members' => [
                    (string) $original->getId()  => ['score' => 0.6],
                    (string) $duplicate->getId() => ['score' => 0.58, 'penalty' => 0.3],
                    (string) $unique->getId()    => ['score' => 0.65],
                ],
            ],
        ];

        $result = $picker->pickCover([$original, $duplicate, $unique], $clusterParams);

        self::assertSame($unique, $result);
    }

    #[Test]
    public function saliencyAlignmentBreaksTies(): void
    {
        $picker = new DefaultCoverPicker();

        $ruleOfThirds = $this->makeMedia(
            id: 501,
            path: '/fixtures/feed/thirds.jpg',
            takenAt: '2024-08-10T15:00:00+00:00',
            size: 4_000_000,
            configure: static function (Media $media): void {
                $media->setWidth(3600);
                $media->setHeight(2400);
                $media->setFeatures([
                    'saliency' => [
                        'center'                 => ['x' => 0.33, 'y' => 0.33],
                        'rule_of_thirds_score'   => 0.85,
                        'confidence'             => 0.9,
                    ],
                ]);
            },
        );

        $centered = $this->makeMedia(
            id: 502,
            path: '/fixtures/feed/centered.jpg',
            takenAt: '2024-08-10T15:02:00+00:00',
            size: 4_050_000,
            configure: static function (Media $media): void {
                $media->setWidth(3600);
                $media->setHeight(2400);
                $media->setFeatures([
                    'saliency' => [
                        'center'               => ['x' => 0.5, 'y' => 0.5],
                        'rule_of_thirds_score' => 0.4,
                        'confidence'           => 0.5,
                    ],
                ]);
            },
        );

        $clusterParams = [
            'member_quality' => [
                'weights' => [
                    'quality'    => 0.5,
                    'aesthetics' => 0.5,
                    'duplicates' => [
                        'phash' => 0.35,
                        'dhash' => 0.25,
                    ],
                ],
            ],
        ];

        $result = $picker->pickCover([$centered, $ruleOfThirds], $clusterParams);

        self::assertSame($ruleOfThirds, $result);
    }
}
