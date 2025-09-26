<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\YearInReviewClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class YearInReviewClusterStrategyTest extends TestCase
{
    #[Test]
    public function buildsClusterForYearsMeetingThresholds(): void
    {
        $strategy = new YearInReviewClusterStrategy(minItemsPerYear: 4, minDistinctMonths: 3);

        $mediaItems = [
            $this->createMedia(501, '2021-01-05 09:00:00', 52.5200, 13.4050),
            $this->createMedia(502, '2021-03-12 10:00:00', 52.5202, 13.4052),
            $this->createMedia(503, '2021-05-25 11:30:00', 52.5204, 13.4054),
            $this->createMedia(504, '2021-10-02 14:15:00', 52.5206, 13.4056),
            // Below threshold for months (only Feb + Mar)
            $this->createMedia(601, '2020-02-01 08:00:00', 48.1371, 11.5753),
            $this->createMedia(602, '2020-02-18 09:00:00', 48.1372, 11.5754),
            $this->createMedia(603, '2020-03-05 10:15:00', 48.1373, 11.5755),
            $this->createMedia(604, '2020-03-20 11:45:00', 48.1374, 11.5756),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('year_in_review', $cluster->getAlgorithm());
        self::assertSame([501, 502, 503, 504], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2021, $params['year']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2021-01-05 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2021-10-02 14:15:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.5203, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(13.4053, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function returnsEmptyWhenYearsLackDistinctMonths(): void
    {
        $strategy = new YearInReviewClusterStrategy(minItemsPerYear: 3, minDistinctMonths: 4);

        $mediaItems = [
            $this->createMedia(701, '2022-01-01 09:00:00', 40.7128, -74.0060),
            $this->createMedia(702, '2022-02-01 10:00:00', 40.7129, -74.0059),
            $this->createMedia(703, '2022-03-01 11:00:00', 40.7130, -74.0058),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/year-in-review-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')));
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);

        return $media;
    }

}
