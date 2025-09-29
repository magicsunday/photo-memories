<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\PanoramaClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class PanoramaClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersWidePanoramas(): void
    {
        $strategy = new PanoramaClusterStrategy(
            minAspect: 2.4,
            sessionGapSeconds: 1800,
            minItemsPerRun: 3,
        );

        $start = new DateTimeImmutable('2024-06-01 12:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->createPanorama(3900 + $i, $start->add(new DateInterval('PT' . ($i * 600) . 'S')));
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('panorama', $clusters[0]->getAlgorithm());
        self::assertSame([3900, 3901, 3902], $clusters[0]->getMembers());
    }

    #[Test]
    public function ignoresPhotosBelowAspectThreshold(): void
    {
        $strategy = new PanoramaClusterStrategy();

        $start = new DateTimeImmutable('2024-06-02 12:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->createNarrowPhoto(4000 + $i, $start->add(new DateInterval('PT' . ($i * 600) . 'S')));
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createPanorama(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('panorama-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 45.0,
            lon: 7.0,
            configure: static function (Media $media): void {
                $media->setWidth(5000);
                $media->setHeight(1000);
            },
            size: 2048,
        );
    }

    private function createNarrowPhoto(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('photo-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media): void {
                $media->setWidth(2000);
                $media->setHeight(1500);
            },
        );
    }

}
