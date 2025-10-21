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
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function itPrefersTravelCandidateWhenTravelSignalsDominate(): void
    {
        $picker = new DefaultCoverPicker();

        [$travelHero, $peopleHero] = $this->createTravelAndPeopleCandidates();

        $clusterParams = [
            'total_travel_km'      => 220.0,
            'travel_waypoints'     => [
                ['lat' => 47.05, 'lon' => 10.23],
                ['lat' => 47.68, 'lon' => 11.02],
                ['lat' => 48.14, 'lon' => 11.58],
            ],
            'people_coverage'      => 0.1,
            'people_face_coverage' => 0.12,
            'people_ratio'         => 0.1,
            'member_quality'       => [
                'summary' => [
                    'quality_avg'    => 0.83,
                    'aesthetics_avg' => 0.79,
                ],
                'weights' => [
                    'quality'    => 0.6,
                    'aesthetics' => 0.4,
                    'duplicates' => [
                        'phash' => 0.45,
                        'dhash' => 0.3,
                    ],
                ],
                'members' => [
                    (string) $travelHero->getId() => [
                        'quality'    => 0.86,
                        'aesthetics' => 0.82,
                    ],
                    (string) $peopleHero->getId() => [
                        'quality'    => 0.92,
                        'aesthetics' => 0.9,
                    ],
                ],
            ],
        ];

        $context = $this->buildPickerContext($picker, [$travelHero, $peopleHero], $clusterParams);

        self::assertGreaterThan(
            $this->resolveTravelScore($picker, $peopleHero, $context),
            $this->resolveTravelScore($picker, $travelHero, $context),
        );

        self::assertGreaterThan(
            $this->resolvePeopleScore($picker, $travelHero, $context),
            $this->resolvePeopleScore($picker, $peopleHero, $context),
        );

        $result = $picker->pickCover([$travelHero, $peopleHero], $clusterParams);

        self::assertSame($travelHero, $result);

        $travelScore = $this->resolveScore($picker, $travelHero, $context);
        $peopleScore = $this->resolveScore($picker, $peopleHero, $context);

        self::assertGreaterThan($peopleScore, $travelScore);
    }

    #[Test]
    public function itPrefersPeopleCandidateWhenPeopleSignalsDominate(): void
    {
        $picker = new DefaultCoverPicker();

        [$travelHero, $peopleHero] = $this->createTravelAndPeopleCandidates();

        $clusterParams = [
            'total_travel_km'       => 0.0,
            'people_coverage'       => 0.94,
            'people_face_coverage'  => 0.96,
            'people_ratio'          => 0.92,
            'people_primary_subject'=> 'friend-alex',
            'member_quality'        => [
                'summary' => [
                    'quality_avg'    => 0.83,
                    'aesthetics_avg' => 0.79,
                ],
                'weights' => [
                    'quality'    => 0.6,
                    'aesthetics' => 0.4,
                    'duplicates' => [
                        'phash' => 0.45,
                        'dhash' => 0.3,
                    ],
                ],
                'members' => [
                    (string) $travelHero->getId() => [
                        'quality'    => 0.86,
                        'aesthetics' => 0.82,
                    ],
                    (string) $peopleHero->getId() => [
                        'quality'    => 0.92,
                        'aesthetics' => 0.9,
                    ],
                ],
            ],
        ];

        $context = $this->buildPickerContext($picker, [$travelHero, $peopleHero], $clusterParams);

        self::assertGreaterThan(
            $this->resolveTravelScore($picker, $peopleHero, $context),
            $this->resolveTravelScore($picker, $travelHero, $context),
        );

        self::assertGreaterThan(
            $this->resolvePeopleScore($picker, $travelHero, $context),
            $this->resolvePeopleScore($picker, $peopleHero, $context),
        );

        $result = $picker->pickCover([$travelHero, $peopleHero], $clusterParams);

        $travelScore = $this->resolveScore($picker, $travelHero, $context);
        $peopleScore = $this->resolveScore($picker, $peopleHero, $context);

        self::assertSame($peopleHero, $result);

        self::assertGreaterThan($travelScore, $peopleScore);
    }

    #[Test]
    public function itBalancesTravelAdvantageAgainstDuplicatePenalty(): void
    {
        $picker = new DefaultCoverPicker();

        $travelDuplicate = $this->makeMedia(
            id: 951,
            path: '/fixtures/feed/travel-duplicate.jpg',
            takenAt: '2024-09-04T07:45:00+00:00',
            lat: 47.5123,
            lon: 11.2456,
            size: 7_600_000,
            configure: static function (Media $media): void {
                $media->setWidth(5400);
                $media->setHeight(3200);
                $media->setOrientation(1);
                $media->setNeedsRotation(false);
                $media->setIsPanorama(true);
                $media->setQualityScore(0.9);
                $media->setContrast(0.74);
                $media->setEntropy(0.7);
                $media->setColorfulness(0.68);
                $media->setHasFaces(true);
                $media->setFacesCount(1);
                $media->setPhash('abcdabcdabcdabcdabcdabcdabcdabcd');
                $media->setDhash('face1234face1234');
                $media->setThumbnails(['default' => '/thumbs/travel-duplicate.jpg']);
                $media->setFeatures([
                    'vision' => [
                        'face_coverage'             => 0.14,
                        'primary_pose'              => 'profile-looking-away',
                        'primary_pose_confidence'   => 0.55,
                    ],
                    'saliency' => [
                        'center'                 => ['x' => 0.52, 'y' => 0.48],
                        'rule_of_thirds_score'   => 0.76,
                        'confidence'             => 0.78,
                    ],
                ]);
            },
        );

        $peopleChampion = $this->makeMedia(
            id: 952,
            path: '/fixtures/feed/people-champion.jpg',
            takenAt: '2024-09-04T07:50:00+00:00',
            size: 5_900_000,
            configure: static function (Media $media): void {
                $media->setWidth(3600);
                $media->setHeight(4800);
                $media->setOrientation(1);
                $media->setNeedsRotation(false);
                $media->setQualityScore(0.88);
                $media->setContrast(0.8);
                $media->setEntropy(0.76);
                $media->setColorfulness(0.73);
                $media->setHasFaces(true);
                $media->setFacesCount(4);
                $media->setPersons(['friend-alex', 'friend-jamie', 'friend-lisa']);
                $media->setFeatures([
                    'vision' => [
                        'face_coverage'             => 0.46,
                        'primary_pose'              => 'front-facing group',
                        'primary_pose_confidence'   => 0.88,
                    ],
                    'saliency' => [
                        'center'                 => ['x' => 0.36, 'y' => 0.38],
                        'rule_of_thirds_score'   => 0.74,
                        'confidence'             => 0.72,
                    ],
                ]);
                $media->setThumbnails(['default' => '/thumbs/people-champion.jpg']);
            },
        );

        $clusterParams = [
            'total_travel_km'      => 185.0,
            'travel_waypoints'     => [
                ['lat' => 47.05, 'lon' => 10.23],
                ['lat' => 47.68, 'lon' => 11.02],
                ['lat' => 48.14, 'lon' => 11.58],
                ['lat' => 48.35, 'lon' => 12.01],
            ],
            'people_coverage'      => 0.86,
            'people_face_coverage' => 0.92,
            'people_ratio'         => 0.84,
            'people_primary_subject'=> 'friend-lisa',
            'member_quality'       => [
                'summary' => [
                    'quality_avg'    => 0.88,
                    'aesthetics_avg' => 0.84,
                ],
                'weights' => [
                    'quality'    => 0.55,
                    'aesthetics' => 0.45,
                    'duplicates' => [
                        'phash' => 0.48,
                        'dhash' => 0.36,
                    ],
                ],
                'members' => [
                    (string) $travelDuplicate->getId() => [
                        'quality'    => 0.91,
                        'aesthetics' => 0.89,
                        'penalty'    => 0.58,
                    ],
                    (string) $peopleChampion->getId() => [
                        'quality'    => 0.87,
                        'aesthetics' => 0.9,
                    ],
                ],
            ],
        ];

        $context = $this->buildPickerContext($picker, [$travelDuplicate, $peopleChampion], $clusterParams);

        self::assertGreaterThan(
            $this->resolveTravelScore($picker, $peopleChampion, $context),
            $this->resolveTravelScore($picker, $travelDuplicate, $context),
        );

        self::assertGreaterThan(
            $this->resolvePeopleScore($picker, $travelDuplicate, $context),
            $this->resolvePeopleScore($picker, $peopleChampion, $context),
        );

        $travelScore = $this->resolveScore($picker, $travelDuplicate, $context);
        $peopleScore = $this->resolveScore($picker, $peopleChampion, $context);

        self::assertGreaterThan($travelScore, $peopleScore);

        $result = $picker->pickCover([$travelDuplicate, $peopleChampion], $clusterParams);

        self::assertSame($peopleChampion, $result);
    }

    #[Test]
    #[DataProvider('provideQualityTieScenarios')]
    public function itBreaksQualityTiesUsingDomainSpecificSignals(string $expected, array $clusterOverrides): void
    {
        $picker = new DefaultCoverPicker();

        if ($expected === 'travel') {
            $travelHero = $this->makeMedia(
                id: 903,
                path: '/fixtures/feed/tie-travel.jpg',
                takenAt: '2024-09-02T12:00:00+00:00',
                lat: 46.5123,
                lon: 11.3567,
                size: 6_300_000,
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(2666);
                    $media->setOrientation(1);
                    $media->setNeedsRotation(false);
                    $media->setQualityScore(0.82);
                    $media->setContrast(0.6);
                    $media->setEntropy(0.58);
                },
            );

            $peopleHero = $this->makeMedia(
                id: 904,
                path: '/fixtures/feed/tie-people.jpg',
                takenAt: '2024-09-02T12:00:00+00:00',
                size: 6_300_000,
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(2666);
                    $media->setOrientation(1);
                    $media->setNeedsRotation(false);
                    $media->setQualityScore(0.82);
                },
            );
        } else {
            $travelHero = $this->makeMedia(
                id: 903,
                path: '/fixtures/feed/tie-travel.jpg',
                takenAt: '2024-09-02T12:00:00+00:00',
                size: 6_200_000,
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(2666);
                    $media->setOrientation(1);
                    $media->setNeedsRotation(false);
                    $media->setQualityScore(0.82);
                },
            );

            $peopleHero = $this->makeMedia(
                id: 904,
                path: '/fixtures/feed/tie-people.jpg',
                takenAt: '2024-09-02T12:00:00+00:00',
                size: 6_200_000,
                configure: static function (Media $media): void {
                    $media->setWidth(4000);
                    $media->setHeight(2666);
                    $media->setOrientation(1);
                    $media->setNeedsRotation(false);
                    $media->setQualityScore(0.82);
                    $media->setHasFaces(true);
                    $media->setFacesCount(2);
                    $media->setPersons(['friend-alex']);
                    $media->setFeatures([
                        'vision' => [
                            'face_coverage'             => 0.52,
                            'primary_pose'              => 'smiling',
                            'primary_pose_confidence'   => 0.9,
                        ],
                    ]);
                },
            );
        }

        $clusterParams = [
            'member_quality' => [
                'summary' => [
                    'quality_avg'    => 0.82,
                    'aesthetics_avg' => 0.8,
                ],
                'weights' => [
                    'quality'    => 0.5,
                    'aesthetics' => 0.5,
                    'duplicates' => [
                        'phash' => 0.4,
                        'dhash' => 0.3,
                    ],
                ],
                'members' => [
                    (string) $travelHero->getId() => [
                        'quality'    => 0.82,
                        'aesthetics' => 0.8,
                    ],
                    (string) $peopleHero->getId() => [
                        'quality'    => 0.82,
                        'aesthetics' => 0.8,
                    ],
                ],
            ],
            'people_ratio' => 0.2,
        ];

        foreach ($clusterOverrides as $key => $value) {
            $clusterParams[$key] = $value;
        }

        $context = $this->buildPickerContext($picker, [$travelHero, $peopleHero], $clusterParams);

        $result = $picker->pickCover([$travelHero, $peopleHero], $clusterParams);

        if ($expected === 'travel') {
            self::assertSame($travelHero, $result);
            self::assertGreaterThan(
                $this->resolveScore($picker, $peopleHero, $context),
                $this->resolveScore($picker, $travelHero, $context),
            );
        } else {
            self::assertSame($peopleHero, $result);
            self::assertGreaterThan(
                $this->resolveScore($picker, $travelHero, $context),
                $this->resolveScore($picker, $peopleHero, $context),
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function provideQualityTieScenarios(): iterable
    {
        yield 'travel weight dominates tie' => [
            'travel',
            [
                'total_travel_km'      => 180.0,
                'people_face_coverage' => 0.0,
                'people_coverage'      => 0.0,
                'people_ratio'         => 0.0,
                'people_primary_subject'=> 'nobody',
                'travel_waypoints'     => [
                    ['lat' => 47.05, 'lon' => 10.23],
                    ['lat' => 47.68, 'lon' => 11.02],
                    ['lat' => 48.14, 'lon' => 11.58],
                ],
            ],
        ];

        yield 'people emphasis dominates tie' => [
            'people',
            [
                'total_travel_km'       => 0.0,
                'people_face_coverage'  => 0.95,
                'people_coverage'       => 0.92,
                'people_ratio'          => 1.0,
                'people_primary_subject'=> 'friend-alex',
            ],
        ];
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
    public function itPrefersMediaContainingThePrimaryPerson(): void
    {
        $picker = new DefaultCoverPicker();

        $sharedSetup = static function (Media $media): void {
            $media->setWidth(4032);
            $media->setHeight(3024);
            $media->setHasFaces(true);
            $media->setFacesCount(2);
            $media->setFeatures([
                'vision' => [
                    'face_coverage'           => 0.45,
                    'primary_pose'            => 'smiling',
                    'primary_pose_confidence' => 0.9,
                ],
            ]);
            $media->setThumbnails(['default' => '/thumbs/vision.jpg']);
        };

        $withoutPrimary = $this->makeMedia(
            id: 601,
            path: '/fixtures/feed/without-primary.jpg',
            takenAt: '2024-09-15T11:30:00+00:00',
            configure: static function (Media $media) use ($sharedSetup): void {
                $sharedSetup($media);
                $media->setPersons(['Alice', 'Charlie']);
            },
        );

        $withPrimary = $this->makeMedia(
            id: 602,
            path: '/fixtures/feed/with-primary.jpg',
            takenAt: '2024-09-15T11:30:00+00:00',
            configure: static function (Media $media) use ($sharedSetup): void {
                $sharedSetup($media);
                $media->setPersons(['Alice', '  jane doe  ']);
            },
        );

        $clusterParams = [
            'people_primary_subject' => '  Jane DOE  ',
            'people_face_coverage'   => 0.5,
        ];

        $result = $picker->pickCover([$withoutPrimary, $withPrimary], $clusterParams);

        self::assertSame($withPrimary, $result);
    }

    #[Test]
    #[DataProvider('providePrimarySubjectScenarios')]
    public function itAppliesPrimaryPersonBonusWhenAvailable(?array $persons, ?string $primary, bool $expectsBonus): void
    {
        $picker = new DefaultCoverPicker();

        $media = $this->makeMedia(
            id: 701,
            path: '/fixtures/feed/primary-check.jpg',
            configure: static function (Media $media) use ($persons): void {
                $media->setHasFaces(true);
                $media->setFacesCount($persons !== null ? count($persons) : 0);
                $media->setPersons($persons);
            },
        );

        $people = [
            'emphasis'     => 0.6,
            'coverage'     => null,
            'faceCoverage' => null,
            'ratio'        => null,
            'primary'      => $primary,
        ];

        $method = new ReflectionMethod(DefaultCoverPicker::class, 'peopleScore');
        $method->setAccessible(true);

        $baseline = $people;
        $baseline['primary'] = null;

        $baselineScore = $method->invoke($picker, $media, $baseline);
        $adjustedScore = $method->invoke($picker, $media, $people);

        if ($expectsBonus) {
            self::assertGreaterThan($baselineScore, $adjustedScore);
        } else {
            self::assertEqualsWithDelta($baselineScore, $adjustedScore, 1e-6);
        }
    }

    /**
     * @return iterable<string, array{0: ?array, 1: ?string, 2: bool}>
     */
    public static function providePrimarySubjectScenarios(): iterable
    {
        yield 'no persons on media' => [null, 'Primary Person', false];
        yield 'no primary identifier provided' => [['Alice', 'Bob'], null, false];
        yield 'multiple persons including primary' => [['Alice', '  jane doe '], '  JANE DOE  ', true];
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

    /**
     * @return array{0: Media, 1: Media}
     */
    private function createTravelAndPeopleCandidates(): array
    {
        $travelHero = $this->makeMedia(
            id: 901,
            path: '/fixtures/feed/travel-hero.jpg',
            takenAt: '2024-09-01T08:00:00+00:00',
            lat: 47.0500,
            lon: 10.2330,
            size: 7_800_000,
            configure: static function (Media $media): void {
                $media->setWidth(5200);
                $media->setHeight(3000);
                $media->setOrientation(1);
                $media->setNeedsRotation(false);
                $media->setIsPanorama(true);
                $media->setQualityScore(0.86);
                $media->setContrast(0.7);
                $media->setEntropy(0.66);
                $media->setColorfulness(0.64);
                $media->setHasFaces(true);
                $media->setFacesCount(1);
                $media->setThumbnails(['default' => '/thumbs/travel-hero.jpg']);
                $media->setFeatures([
                    'saliency' => [
                        'center'                 => ['x' => 0.48, 'y' => 0.52],
                        'rule_of_thirds_score'   => 0.78,
                        'confidence'             => 0.82,
                    ],
                    'vision' => [
                        'face_coverage' => 0.16,
                    ],
                ]);
            },
        );

        $peopleHero = $this->makeMedia(
            id: 902,
            path: '/fixtures/feed/people-hero.jpg',
            takenAt: '2024-09-01T08:05:00+00:00',
            size: 5_600_000,
            configure: static function (Media $media): void {
                $media->setWidth(3000);
                $media->setHeight(4200);
                $media->setOrientation(1);
                $media->setNeedsRotation(false);
                $media->setQualityScore(0.92);
                $media->setContrast(0.78);
                $media->setEntropy(0.74);
                $media->setColorfulness(0.72);
                $media->setHasFaces(true);
                $media->setFacesCount(3);
                $media->setPersons(['friend-alex', 'friend-jamie']);
                $media->setFeatures([
                    'vision' => [
                        'face_coverage'             => 0.4,
                        'primary_pose'              => 'front-facing group',
                        'primary_pose_confidence'   => 0.85,
                    ],
                    'saliency' => [
                        'center'                 => ['x' => 0.34, 'y' => 0.36],
                        'rule_of_thirds_score'   => 0.72,
                        'confidence'             => 0.7,
                    ],
                ]);
                $media->setThumbnails(['default' => '/thumbs/people-hero.jpg']);
            },
        );

        return [$travelHero, $peopleHero];
    }

    /**
     * @param list<Media> $members
     * @param array<string, mixed> $clusterParams
     *
     * @return array<string, mixed>
     */
    private function buildPickerContext(DefaultCoverPicker $picker, array $members, array $clusterParams): array
    {
        $method = new ReflectionMethod(DefaultCoverPicker::class, 'buildContext');
        $method->setAccessible(true);

        /** @var array<string, mixed> $context */
        $context = $method->invoke($picker, $members, $clusterParams);

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveScore(DefaultCoverPicker $picker, Media $media, array $context): float
    {
        $method = new ReflectionMethod(DefaultCoverPicker::class, 'score');
        $method->setAccessible(true);

        return $method->invoke($picker, $media, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolvePeopleScore(DefaultCoverPicker $picker, Media $media, array $context): float
    {
        $method = new ReflectionMethod(DefaultCoverPicker::class, 'peopleScore');
        $method->setAccessible(true);

        return $method->invoke($picker, $media, $context['people']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveTravelScore(DefaultCoverPicker $picker, Media $media, array $context): float
    {
        $normalise = new ReflectionMethod(DefaultCoverPicker::class, 'normalizeDimensions');
        $normalise->setAccessible(true);

        [$width, $height] = $normalise->invoke($picker, $media->getWidth() ?? 0, $media->getHeight() ?? 0, $media);

        $landscape = ($width >= $height) ? 1.0 : 0.0;
        $areaMp    = ($width > 0 && $height > 0) ? (($width * $height) / 1_000_000.0) : 0.0;

        $method = new ReflectionMethod(DefaultCoverPicker::class, 'travelScore');
        $method->setAccessible(true);

        return $method->invoke($picker, $media, $context['travel'], $landscape, $areaMp);
    }
}
