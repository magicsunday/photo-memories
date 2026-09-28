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
use MagicSunday\Memories\Service\Metadata\Exif\DefaultExifValueAccessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\OrientationExifMetadataProcessor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OrientationExifMetadataProcessorTest extends TestCase
{
    #[Test]
    public function marksMediaForRotationWhenExifRequiresIt(): void
    {
        $media = $this->makeMedia(
            id: 101,
            path: '/fixtures/orientation.jpg',
        );

        $processor = new OrientationExifMetadataProcessor(new DefaultExifValueAccessor());
        $processor->process([
            'IFD0' => ['Orientation' => 6],
        ], $media);

        self::assertSame(6, $media->getOrientation());
        self::assertTrue($media->needsRotation());
    }

    #[Test]
    public function clearsRotationFlagForNormalOrientation(): void
    {
        $media = $this->makeMedia(
            id: 102,
            path: '/fixtures/orientation-normal.jpg',
            configure: static function (Media $entity): void {
                $entity->setNeedsRotation(true);
            },
        );

        $processor = new OrientationExifMetadataProcessor(new DefaultExifValueAccessor());
        $processor->process([
            'IFD0' => ['Orientation' => 1],
        ], $media);

        self::assertSame(1, $media->getOrientation());
        self::assertFalse($media->needsRotation());
    }
}
