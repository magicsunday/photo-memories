<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\PortraitOrientationClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class PortraitOrientationClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersPortraitSessions(): void
    {
        $strategy = new PortraitOrientationClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('portrait_orientation', $cluster->getAlgorithm());
        self::assertSame([3700, 3701, 3702, 3703], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertArrayHasKey('quality_avg', $params);
        self::assertGreaterThan(0.0, $params['quality_avg']);
        self::assertArrayHasKey('aesthetics_score', $params);
    }

    #[Test]
    public function skipsLandscapePhotos(): void
    {
        $strategy = new PortraitOrientationClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
        );

        $start = new DateTimeImmutable('2024-04-11 10:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 4; ++$i) {
            $media   = $this->createLandscapeMedia(3800 + $i, $start->add(new DateInterval('PT' . ($i * 600) . 'S')));
            $items[] = $media;
        }

        self::assertSame([], $strategy->draft($items, Context::fromScope($items)));
    }

    #[Test]
    public function acceptsPortraitFlagWithoutDimensions(): void
    {
        $strategy = new PortraitOrientationClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minPortraitRatio: 1.2,
            sessionGapSeconds: 900,
            minItemsPerRun: 1,
        );

        $media = $this->makeMediaFixture(
            id: 3900,
            filename: 'flagged-portrait.jpg',
            takenAt: new DateTimeImmutable('2024-04-12 09:00:00', new DateTimeZone('UTC')),
            configure: static function (Media $media): void {
                $media->setIsPortrait(true);
                $media->setPersons(['Cara']);
            },
        );

        $clusters = $strategy->draft([$media], Context::fromScope([$media]));

        self::assertCount(1, $clusters);
        self::assertSame([3900], $clusters[0]->getMembers());
    }

    #[Test]
    public function rejectsItemsWithFalsePortraitFlagEvenWithPortraitRatio(): void
    {
        $strategy = new PortraitOrientationClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minPortraitRatio: 1.2,
            sessionGapSeconds: 900,
            minItemsPerRun: 1,
        );

        $media = $this->makeMediaFixture(
            id: 3901,
            filename: 'flagged-landscape.jpg',
            takenAt: new DateTimeImmutable('2024-04-12 10:00:00', new DateTimeZone('UTC')),
            configure: static function (Media $media): void {
                $media->setWidth(1000);
                $media->setHeight(1600);
                $media->setPersons(['Dana']);
                $media->setIsPortrait(false);
            },
        );

        self::assertSame([], $strategy->draft([$media], Context::fromScope([$media])));
    }

    #[Test]
    public function requiresFacesOrPersonsEvenWhenPortraitFlagIsTrue(): void
    {
        $strategy = new PortraitOrientationClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minPortraitRatio: 1.2,
            sessionGapSeconds: 900,
            minItemsPerRun: 1,
        );

        $media = $this->makeMediaFixture(
            id: 3902,
            filename: 'flagged-portrait-missing-persons.jpg',
            takenAt: new DateTimeImmutable('2024-04-12 11:00:00', new DateTimeZone('UTC')),
            configure: static function (Media $media): void {
                $media->setIsPortrait(true);
            },
        );

        self::assertSame([], $strategy->draft([$media], Context::fromScope([$media])));
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
                $media->setPersons(['Alice']);
                $media->setSharpness(0.62);
                $media->setIso(125);
                $media->setBrightness(0.58);
                $media->setContrast(0.66);
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
                $media->setPersons(['Bob']);
            },
        );
    }
}
