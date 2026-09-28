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
use MagicSunday\Memories\Service\Metadata\Exif\Processor\CompositeImageExifMetadataProcessor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CompositeImageExifMetadataProcessorTest extends TestCase
{
    #[Test]
    public function populatesCompositeMetadata(): void
    {
        $media = $this->makeMedia(
            id: 2,
            path: '/fixtures/exif/composite.jpg',
        );

        $processor = new CompositeImageExifMetadataProcessor(new DefaultExifValueAccessor());

        $exif = [
            'EXIF' => [
                'CompositeImage'                      => 3,
                'SourceImageNumberOfCompositeImage'   => '5',
                'SourceExposureTimesOfCompositeImage' => '1/200,1/100',
            ],
        ];

        $processor->process($exif, $media);

        self::assertSame(3, $media->getCompositeImage());
        self::assertSame(5, $media->getCompositeImageSourceCount());
        self::assertSame('1/200,1/100', $media->getCompositeImageExposureTimes());
    }
}
