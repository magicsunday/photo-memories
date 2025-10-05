<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata\Exif\Processor;

use MagicSunday\Memories\Service\Metadata\Exif\DefaultExifValueAccessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\CameraExifMetadataProcessor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CameraExifMetadataProcessorTest extends TestCase
{
    #[Test]
    public function populatesCameraAndLensMetadata(): void
    {
        $media = $this->makeMedia(
            id: 1,
            path: '/fixtures/exif/camera.jpg',
        );

        $processor = new CameraExifMetadataProcessor(new DefaultExifValueAccessor());

        $exif = [
            'IFD0' => [
                'Make'  => 'Canon',
                'Model' => 'Canon EOS 5D Mark IV',
            ],
            'EXIF' => [
                'CameraOwnerName'   => 'Rico Sonntag',
                'BodySerialNumber'  => '123ABC456',
                'LensMake'          => 'Canon',
                'LensModel'         => 'EF 24-70mm f/2.8L II USM',
                'LensSerialNumber'  => 'LN987654321',
                'LensSpecification' => ['24/1', '70/1', '28/10', '40/10'],
            ],
        ];

        $processor->process($exif, $media);

        self::assertSame('Canon', $media->getCameraMake());
        self::assertSame('Canon EOS 5D Mark IV', $media->getCameraModel());
        self::assertSame('Rico Sonntag', $media->getCameraOwner());
        self::assertSame('123ABC456', $media->getCameraBodySerial());
        self::assertSame('Canon', $media->getLensMake());
        self::assertSame('EF 24-70mm f/2.8L II USM', $media->getLensModel());
        self::assertSame('24-70mm f/2.8-4', $media->getLensSpecification());
        self::assertSame('LN987654321', $media->getLensSerialNumber());
    }
}
