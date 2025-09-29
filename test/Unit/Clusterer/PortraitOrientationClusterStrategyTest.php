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
use MagicSunday\Memories\Clusterer\PortraitOrientationClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PortraitOrientationClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersPortraitSessions(): void
    {
        $strategy = new PortraitOrientationClusterStrategy(
            minPortraitRatio: 1.2,
            sessionGapSeconds: 900,
            minItemsPerRun: 4,
        );

        $start = new DateTimeImmutable('2024-04-10 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 4; ++$i) {
            $media   = $this->createPortraitMedia(3700 + $i, $start->add(new DateInterval('PT' . ($i * 600) . 'S')));
            $items[] = $media;
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('portrait_orientation', $cluster->getAlgorithm());
        self::assertSame([3700, 3701, 3702, 3703], $cluster->getMembers());
    }

    #[Test]
    public function skipsLandscapePhotos(): void
    {
        $strategy = new PortraitOrientationClusterStrategy();

        $start = new DateTimeImmutable('2024-04-11 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 4; ++$i) {
            $media   = $this->createLandscapeMedia(3800 + $i, $start->add(new DateInterval('PT' . ($i * 600) . 'S')));
            $items[] = $media;
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createPortraitMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('portrait-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 48.0,
            lon: 11.0,
            configure: static function (Media $media): void {
                $media->setWidth(1000);
                $media->setHeight(1500);
            },
        );
    }

    private function createLandscapeMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('landscape-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media): void {
                $media->setWidth(1600);
                $media->setHeight(900);
            },
        );
    }
}
