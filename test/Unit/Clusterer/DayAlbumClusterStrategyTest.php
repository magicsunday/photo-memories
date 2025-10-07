<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\DayAlbumClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DayAlbumClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsMediaByLocalCalendarDay(): void
    {
        $strategy = new DayAlbumClusterStrategy(
            localTimeHelper: new LocalTimeHelper('America/Los_Angeles'),
            minItemsPerDay: 2,
        );

        $mediaItems = [
            $this->createMedia(101, '2022-06-01 23:30:00', 34.0522, -118.2437),
            $this->createMedia(102, '2022-06-02 00:15:00', 34.0524, -118.2439),
            // Falls below the minimum for its own day
            $this->createMedia(103, '2022-06-02 07:30:00', 34.0526, -118.2441),
        ];

        $this->assignTags($mediaItems[0], [
            ['label' => 'Strand', 'score' => 0.9],
            ['label' => 'Familie', 'score' => 0.8],
        ], ['Strand', 'Sonne']);
        $this->assignTags($mediaItems[1], [
            ['label' => 'Strand', 'score' => 0.7],
            ['label' => 'Sonne', 'score' => 0.6],
        ], ['Strand', 'Familie']);

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('day_album', $cluster->getAlgorithm());
        self::assertSame([101, 102], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2022, $params['year']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2022-06-01 23:30:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2022-06-02 00:15:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);
        self::assertArrayHasKey('scene_tags', $params);
        self::assertArrayHasKey('keywords', $params);
        $sceneTags = $params['scene_tags'];
        self::assertCount(3, $sceneTags);
        self::assertSame('Strand', $sceneTags[0]['label']);
        self::assertEqualsWithDelta(0.9, $sceneTags[0]['score'], 0.0001);
        self::assertSame('Familie', $sceneTags[1]['label']);
        self::assertEqualsWithDelta(0.8, $sceneTags[1]['score'], 0.0001);
        self::assertSame('Sonne', $sceneTags[2]['label']);
        self::assertEqualsWithDelta(0.6, $sceneTags[2]['score'], 0.0001);
        self::assertSame(['Strand', 'Familie', 'Sonne'], $params['keywords']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(34.0523, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(-118.2438, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function returnsEmptyWhenNoDayMeetsMinimumItemCount(): void
    {
        $strategy = new DayAlbumClusterStrategy(
            localTimeHelper: new LocalTimeHelper('UTC'),
            minItemsPerDay: 3,
        );

        $mediaItems = [
            $this->createMedia(201, '2022-08-01 09:00:00', 52.5, 13.4),
            $this->createMedia(202, '2022-08-01 10:00:00', 52.5002, 13.4002),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function honoursCapturedLocalWhenDifferentFromFallback(): void
    {
        $strategy = new DayAlbumClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            minItemsPerDay: 2,
        );

        $mediaItems = [
            $this->createShiftedMedia(501, '2023-01-01 07:30:00', '2022-12-31 23:30:00'),
            $this->createShiftedMedia(502, '2023-01-01 07:50:00', '2022-12-31 23:50:00'),
            $this->createShiftedMedia(503, '2023-01-01 08:30:00', '2023-01-01 00:30:00'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        self::assertSame([501, 502], $clusters[0]->getMembers());
    }

    #[Test]
    public function addsCalendarFlagsAndQualityMetrics(): void
    {
        $strategy = new DayAlbumClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            minItemsPerDay: 2,
        );

        $mediaItems = [
            $this->createAnnotatedMedia(801, '2023-12-24 09:00:00'),
            $this->createAnnotatedMedia(802, '2023-12-24 10:00:00'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $params = $clusters[0]->getParams();

        self::assertArrayHasKey('isWeekend', $params);
        self::assertTrue($params['isWeekend']);
        self::assertSame('holiday-winter-2023', $params['holidayId']);
        self::assertEqualsWithDelta(1.0, $params['quality_avg'], 1e-9);
        self::assertEqualsWithDelta(1.0, $params['aesthetics_score'], 1e-9);
        self::assertEqualsWithDelta(1.0, $params['quality_resolution'], 1e-9);
        self::assertEqualsWithDelta(1.0, $params['quality_sharpness'], 1e-9);
        self::assertEqualsWithDelta(1.0, $params['quality_iso'], 1e-9);
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('day-album-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            configure: static function (Media $media): void {
                $media->setCapturedLocal(null);
                $media->setTimezoneOffsetMin(-420);
            },
        );
    }

    private function createShiftedMedia(int $id, string $takenAtUtc, string $capturedLocal): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('day-album-%d-shifted.jpg', $id),
            takenAt: $takenAtUtc,
            configure: static function (Media $media) use ($capturedLocal): void {
                $local = new DateTimeImmutable($capturedLocal, new DateTimeZone('America/Los_Angeles'));
                $media->setCapturedLocal($local);
                $media->setTimezoneOffsetMin(-480);
            },
        );
    }

    private function createAnnotatedMedia(int $id, string $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('day-album-quality-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media): void {
                $media->setFeatures([
                    'calendar' => [
                        'isWeekend' => true,
                        'holidayId' => 'holiday-winter-2023',
                    ],
                ]);
                $media->setWidth(4000);
                $media->setHeight(3000);
                $media->setSharpness(1.0);
                $media->setIso(50);
                $media->setBrightness(0.55);
                $media->setContrast(1.0);
                $media->setEntropy(1.0);
                $media->setColorfulness(1.0);
            },
        );
    }

    /**
     * @param list<array{label: string, score: float}> $sceneTags
     * @param list<string>                             $keywords
     */
    private function assignTags(Media $media, array $sceneTags, array $keywords): void
    {
        $media->setSceneTags($sceneTags);
        $media->setKeywords($keywords);
    }
}
