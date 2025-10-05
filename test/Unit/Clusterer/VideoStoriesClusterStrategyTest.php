<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\VideoStoriesClusterStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class VideoStoriesClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersVideosByLocalDay(): void
    {
        $strategy = new VideoStoriesClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            minItemsPerDay: 2,
        );

        $base          = new DateTimeImmutable('2024-03-15 08:00:00', new DateTimeZone('UTC'));
        $videos        = [];
        $durations     = [10.0, 20.0, null];
        $slowMoFlags   = [true, false, null];
        $stabilisation = [true, false, true];

        $munich = $this->makeLocation(
            providerPlaceId: 'video-munich',
            displayName: 'München Innenstadt',
            lat: 48.1371,
            lon: 11.5753,
            city: 'Munich',
            country: 'Germany',
            configure: static function (Location $location): void {
                $location->setState('Bayern');
            },
        );

        for ($i = 0; $i < 3; ++$i) {
            $video = $this->createVideo(3300 + $i, $base->add(new DateInterval('PT' . ($i * 1800) . 'S')), $munich);

            $duration = $durations[$i];
            if ($duration !== null) {
                $video->setVideoDurationS($duration);
            }

            $isSlowMo = $slowMoFlags[$i];
            if ($isSlowMo !== null) {
                $video->setIsSlowMo($isSlowMo);
            }

            $hasStabilization = $stabilisation[$i];
            if ($hasStabilization !== null) {
                $video->setVideoHasStabilization($hasStabilization);
            }

            if ($i === 0) {
                $video->setSceneTags([
                    ['label' => 'Filmabend', 'score' => 0.88],
                ]);
                $video->setKeywords(['Filmabend']);
            }

            $videos[] = $video;
        }

        $clusters = $strategy->cluster($videos);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('video_stories', $cluster->getAlgorithm());
        self::assertSame([3300, 3301, 3302], $cluster->getMembers());

        $params = $cluster->getParams();

        self::assertArrayHasKey('video_count', $params);
        self::assertArrayHasKey('video_duration_total_s', $params);
        self::assertArrayHasKey('video_slow_mo_count', $params);
        self::assertArrayHasKey('video_stabilized_count', $params);

        self::assertSame(3, $params['video_count']);
        self::assertSame(30.0, $params['video_duration_total_s']);
        self::assertSame(1, $params['video_slow_mo_count']);
        self::assertSame(2, $params['video_stabilized_count']);
        self::assertSame([
            ['label' => 'Filmabend', 'score' => 0.88],
        ], $params['scene_tags']);
        self::assertSame(['Filmabend'], $params['keywords']);
        self::assertSame('Munich', $params['place_city']);
        self::assertSame('Germany', $params['place_country']);
        self::assertArrayHasKey('place', $params);
        self::assertNotSame('', $params['place']);
    }

    #[Test]
    public function ignoresNonVideoMedia(): void
    {
        $strategy = new VideoStoriesClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
        );

        $items = [
            $this->createPhoto(3400, new DateTimeImmutable('2024-03-16 08:00:00', new DateTimeZone('UTC'))),
            $this->createPhoto(3401, new DateTimeImmutable('2024-03-16 09:00:00', new DateTimeZone('UTC'))),
        ];

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function recognisesVideoFlagWithoutMimeInformation(): void
    {
        $strategy = new VideoStoriesClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            minItemsPerDay: 1,
        );

        $video = $this->makeMediaFixture(
            id: 3450,
            filename: 'video-3450.mp4',
            takenAt: new DateTimeImmutable('2024-03-17 08:00:00', new DateTimeZone('UTC')),
            configure: static function (Media $media): void {
                $media->setIsVideo(true);
            },
        );

        $clusters = $strategy->cluster([$video]);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('video_stories', $cluster->getAlgorithm());
        self::assertSame([3450], $cluster->getMembers());

        $params = $cluster->getParams();

        self::assertSame(1, $params['video_count']);
    }

    #[Test]
    public function respectsExplicitNonVideoFlagEvenWithVideoMime(): void
    {
        $strategy = new VideoStoriesClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            locationHelper: LocationHelper::createDefault(),
            minItemsPerDay: 1,
        );

        $nonVideo = $this->makeMediaFixture(
            id: 3451,
            filename: 'video-3451.mp4',
            takenAt: new DateTimeImmutable('2024-03-17 10:00:00', new DateTimeZone('UTC')),
            configure: static function (Media $media): void {
                $media->setMime('video/mp4');
                $media->setIsVideo(false);
                $media->setIndexedAt(new DateTimeImmutable('2024-03-17 11:00:00', new DateTimeZone('UTC')));
            },
        );

        self::assertSame([], $strategy->cluster([$nonVideo]));
    }

    private function createVideo(int $id, DateTimeImmutable $takenAt, ?Location $location = null): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('video-%d.mp4', $id),
            takenAt: $takenAt,
            lat: 48.1,
            lon: 11.6,
            configure: static function (Media $media): void {
                $media->setMime('video/mp4');
            },
            size: 4096,
            location: $location,
        );
    }

    private function createPhoto(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('photo-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media): void {
                $media->setMime('image/jpeg');
            },
        );
    }
}
