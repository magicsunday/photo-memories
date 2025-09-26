<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\PhotoMotifClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class PhotoMotifClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsPhotosByDetectedMotifAndSession(): void
    {
        $strategy = new PhotoMotifClusterStrategy(
            sessionGapSeconds: 36 * 3600,
            minItemsPerMotif: 6,
        );

        $start = new DateTimeImmutable('2023-09-01 08:00:00', new DateTimeZone('UTC'));
        $mediaItems = [];
        for ($i = 0; $i < 6; $i++) {
            $mediaItems[] = $this->createMedia(
                300 + $i,
                $start->add(new DateInterval('PT' . ($i * 600) . 'S')),
                "wander-berge-{$i}.jpg",
                47.5 + $i * 0.001,
                11.3 + $i * 0.001,
            );
        }

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('photo_motif', $cluster->getAlgorithm());
        self::assertSame('Berge', $cluster->getParams()['label']);
        self::assertSame('mountains', $cluster->getParams()['motif']);
        self::assertSame([300, 301, 302, 303, 304, 305], $cluster->getMembers());
    }

    #[Test]
    public function ignoresMotifsBelowThreshold(): void
    {
        $strategy = new PhotoMotifClusterStrategy(
            sessionGapSeconds: 36 * 3600,
            minItemsPerMotif: 6,
        );

        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                400 + $i,
                new DateTimeImmutable('2023-07-10 09:00:00', new DateTimeZone('UTC')),
                "beach-day-{$i}.jpg",
                36.0,
                -5.0,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $filename, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/' . $filename,
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 512,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);

        return $media;
    }

}
