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
use MagicSunday\Memories\Clusterer\PetMomentsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PetMomentsClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersPetPhotosWithinSession(): void
    {
        $strategy = new PetMomentsClusterStrategy(
            sessionGapSeconds: 1200,
            minItemsPerRun: 6,
        );

        $start = new DateTimeImmutable('2024-01-20 15:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->createMedia(
                3500 + $i,
                $start->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('pet_moments', $clusters[0]->getAlgorithm());
        self::assertSame(range(3500, 3505), $clusters[0]->getMembers());
    }

    #[Test]
    public function enforcesMinimumItemCount(): void
    {
        $strategy = new PetMomentsClusterStrategy();

        $start = new DateTimeImmutable('2024-01-21 15:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 4; ++$i) {
            $items[] = $this->createMedia(
                3600 + $i,
                $start->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('dog-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 52.5,
            lon: 13.4,
        );
    }
}
