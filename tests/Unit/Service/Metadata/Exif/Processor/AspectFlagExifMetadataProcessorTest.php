<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata\Exif\Processor;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\AspectFlagExifMetadataProcessor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AspectFlagExifMetadataProcessorTest extends TestCase
{
    #[Test]
    public function marksTallMediaAsPortraitAndClearsPanorama(): void
    {
        $media = $this->makeMedia(
            id: 201,
            path: '/fixtures/portrait.jpg',
            configure: static function (Media $entity): void {
                $entity->setWidth(900);
                $entity->setHeight(1500);
                $entity->setIsPanorama(true);
            },
        );

        $processor = new AspectFlagExifMetadataProcessor();
        $processor->process([], $media);

        self::assertTrue($media->isPortrait());
        self::assertFalse($media->isPanorama());
    }

    #[Test]
    public function marksWideMediaAsPanoramaAndClearsPortrait(): void
    {
        $media = $this->makeMedia(
            id: 202,
            path: '/fixtures/panorama.jpg',
            configure: static function (Media $entity): void {
                $entity->setWidth(3600);
                $entity->setHeight(1400);
                $entity->setIsPortrait(true);
            },
        );

        $processor = new AspectFlagExifMetadataProcessor();
        $processor->process([], $media);

        self::assertFalse($media->isPortrait());
        self::assertTrue($media->isPanorama());
    }

    #[Test]
    public function clearsFlagsForNeutralAspectRatio(): void
    {
        $media = $this->makeMedia(
            id: 203,
            path: '/fixtures/neutral.jpg',
            configure: static function (Media $entity): void {
                $entity->setWidth(2000);
                $entity->setHeight(2000);
                $entity->setIsPortrait(true);
                $entity->setIsPanorama(true);
            },
        );

        $processor = new AspectFlagExifMetadataProcessor();
        $processor->process([], $media);

        self::assertFalse($media->isPortrait());
        self::assertFalse($media->isPanorama());
    }

    #[Test]
    public function honorsOrientationWhenDerivingPortraitFlag(): void
    {
        $media = $this->makeMedia(
            id: 204,
            path: '/fixtures/rotated-landscape.jpg',
            configure: static function (Media $entity): void {
                $entity->setWidth(2400);
                $entity->setHeight(1600);
                $entity->setOrientation(6);
                $entity->setIsPortrait(false);
                $entity->setIsPanorama(true);
            },
        );

        $processor = new AspectFlagExifMetadataProcessor();
        $processor->process([], $media);

        self::assertTrue($media->isPortrait());
        self::assertFalse($media->isPanorama());
    }
}
