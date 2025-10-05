<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata\Exif;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Exif\Contract\ExifMetadataProcessorInterface;
use MagicSunday\Memories\Service\Metadata\Exif\DefaultExifValueAccessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\AspectFlagExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\CameraExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\CompositeImageExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\DimensionsExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\FormatFlagExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\GpsExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\ExifMetadataExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

final class ExifMetadataExtractorTest extends TestCase
{
    #[Test]
    #[DataProvider('computedDimensionProvider')]
    public function derivesAspectFlagsFromComputedDimensions(
        int $computedWidth,
        int $computedHeight,
        ?bool $expectedPortrait,
        ?bool $expectedPanorama,
    ): void {
        $media = $this->makeMedia(
            id: 1,
            path: '/fixtures/exif/test.jpg',
            configure: static function (Media $value): void {
                $value->setMime('image/jpeg');
            },
        );

        $exif = [
            'COMPUTED' => [
                'Width'  => $computedWidth,
                'Height' => $computedHeight,
            ],
        ];

        $extractor = new ExifMetadataExtractor($this->createProcessors());

        $this->runProcessors($extractor, $exif, $media);

        self::assertSame($computedWidth, $media->getWidth());
        self::assertSame($computedHeight, $media->getHeight());
        self::assertSame($expectedPortrait, $media->isPortrait());
        self::assertSame($expectedPanorama, $media->isPanorama());
    }

    #[Test]
    public function setsFormatFlagsFromFileType(): void
    {
        $extractor = new ExifMetadataExtractor($this->createProcessors());

        $heicMedia = $this->makeMedia(
            id: 11,
            path: '/fixtures/exif/heic.heic',
        );
        $heicExif = [
            'FILE' => ['FileType' => 'HEIC'],
        ];
        $this->runProcessors($extractor, $heicExif, $heicMedia);
        self::assertTrue($heicMedia->isHeic());
        self::assertFalse($heicMedia->isRaw());

        $rawMedia = $this->makeMedia(
            id: 12,
            path: '/fixtures/exif/raw.cr3',
        );
        $rawExif = [
            'FILE' => ['FileType' => 'CR3'],
        ];
        $this->runProcessors($extractor, $rawExif, $rawMedia);
        self::assertTrue($rawMedia->isRaw());
        self::assertFalse($rawMedia->isHeic());

        $hevcMedia = $this->makeMedia(
            id: 13,
            path: '/fixtures/exif/video.mov',
        );
        $hevcExif = [
            'FILE' => ['FileType' => 'HEVC'],
        ];
        $this->runProcessors($extractor, $hevcExif, $hevcMedia);
        self::assertTrue($hevcMedia->isHevc());
    }

    #[Test]
    public function normalizesUndefinedTagOffsetsToNamedKeys(): void
    {
        $extractor = new ExifMetadataExtractor([]);

        $method = new ReflectionMethod($extractor, 'normalizeUndefinedTagOffsets');
        $method->setAccessible(true);

        $exif = [
            'EXIF' => [
                'UndefinedTag:0x882A' => [120, 120],
                'UndefinedTag:0xA420' => 'unique-image-id',
                'UndefinedTag:0xA430' => 'Owner',
                'UndefinedTag:0xA431' => 'BodySerial',
                'UndefinedTag:0xA432' => ['24/1', '70/1', '0/1', '0/1'],
                'UndefinedTag:0xA433' => 'Lens Corp.',
                'UndefinedTag:0xA434' => 'Lens Model X',
                'UndefinedTag:0xA435' => 'LensSerial',
                'UndefinedTag:0xA460' => 2.2,
                'UndefinedTag:0xA461' => 1,
                'UndefinedTag:0xA462' => 2,
                'UndefinedTag:0xA463' => '1/200',
            ],
            'GPS' => [
                'UndefinedTag:0x001F' => 0.85,
            ],
        ];

        /** @var array<string, mixed> $normalized */
        $normalized = $method->invoke($extractor, $exif);

        self::assertSame([120, 120], $normalized['EXIF']['TimeZoneOffset']);
        self::assertSame('unique-image-id', $normalized['EXIF']['ImageUniqueID']);
        self::assertSame('Owner', $normalized['EXIF']['CameraOwnerName']);
        self::assertSame('BodySerial', $normalized['EXIF']['BodySerialNumber']);
        self::assertSame(['24/1', '70/1', '0/1', '0/1'], $normalized['EXIF']['LensSpecification']);
        self::assertSame('Lens Corp.', $normalized['EXIF']['LensMake']);
        self::assertSame('Lens Model X', $normalized['EXIF']['LensModel']);
        self::assertSame('LensSerial', $normalized['EXIF']['LensSerialNumber']);
        self::assertSame(2.2, $normalized['EXIF']['Gamma']);
        self::assertSame(1, $normalized['EXIF']['CompositeImage']);
        self::assertSame(2, $normalized['EXIF']['SourceImageNumberOfCompositeImage']);
        self::assertSame('1/200', $normalized['EXIF']['SourceExposureTimesOfCompositeImage']);
        self::assertSame(0.85, $normalized['GPS']['GPSHPositioningError']);
    }

    #[Test]
    public function storesGpsAccuracyFromNormalizedAlias(): void
    {
        $media = $this->makeMedia(
            id: 1,
            path: '/fixtures/exif/accuracy.jpg',
            configure: static function (Media $value): void {
                $value->setMime('image/jpeg');
            },
        );

        $exif = [
            'GPS' => [
                'GPSLatitude'         => ['52/1', '30/1', '0/1'],
                'GPSLatitudeRef'      => 'N',
                'GPSLongitude'        => ['13/1', '24/1', '0/1'],
                'GPSLongitudeRef'     => 'E',
                'UndefinedTag:0x001F' => '5/2',
            ],
        ];

        $extractor = new ExifMetadataExtractor($this->createProcessors());

        $this->runProcessors($extractor, $exif, $media);

        self::assertSame(2.5, $media->getGpsAccuracyM());
    }

    #[Test]
    public function storesCameraAndCompositeMetadataFromAliases(): void
    {
        $media = $this->makeMedia(
            id: 2,
            path: '/fixtures/exif/camera-composite.jpg',
        );

        $exif = [
            'IFD0' => [
                'Make'  => 'Fujifilm',
                'Model' => 'X-T5',
            ],
            'EXIF' => [
                'CameraOwnerName'                     => 'Jane Doe',
                'BodySerialNumber'                    => 'FUJI123456',
                'LensMake'                            => 'Fujinon',
                'LensModel'                           => 'XF 16-80mm F4 R OIS WR',
                'LensSpecification'                   => ['16/1', '80/1', '40/10', '40/10'],
                'LensSerialNumber'                    => 'FUJILENS001',
                'CompositeImage'                      => 2,
                'SourceImageNumberOfCompositeImage'   => 4,
                'SourceExposureTimesOfCompositeImage' => '1/200;1/60;1/30;1/15',
            ],
        ];

        $extractor = new ExifMetadataExtractor($this->createProcessors());

        $this->runProcessors($extractor, $exif, $media);

        self::assertSame('Fujifilm', $media->getCameraMake());
        self::assertSame('X-T5', $media->getCameraModel());
        self::assertSame('Jane Doe', $media->getCameraOwner());
        self::assertSame('FUJI123456', $media->getCameraBodySerial());
        self::assertSame('Fujinon', $media->getLensMake());
        self::assertSame('XF 16-80mm F4 R OIS WR', $media->getLensModel());
        self::assertSame('16-80mm f/4', $media->getLensSpecification());
        self::assertSame('FUJILENS001', $media->getLensSerialNumber());
        self::assertSame(2, $media->getCompositeImage());
        self::assertSame(4, $media->getCompositeImageSourceCount());
        self::assertSame('1/200;1/60;1/30;1/15', $media->getCompositeImageExposureTimes());
    }

    /**
     * @return iterable<string, array{int, int, ?bool, ?bool}>
     */
    public static function computedDimensionProvider(): iterable
    {
        yield 'portrait' => [
            2000,
            3200,
            true,
            null,
        ];

        yield 'panorama' => [
            4800,
            1800,
            null,
            true,
        ];

        yield 'standard' => [
            4000,
            3000,
            null,
            null,
        ];
    }

    /**
     * @return list<ExifMetadataProcessorInterface>
     */
    private function createProcessors(): array
    {
        $accessor = new DefaultExifValueAccessor();

        $processors = [
            new FormatFlagExifMetadataProcessor($accessor),
            new DimensionsExifMetadataProcessor(),
            new AspectFlagExifMetadataProcessor(),
            new CameraExifMetadataProcessor($accessor),
            new CompositeImageExifMetadataProcessor($accessor),
            new GpsExifMetadataProcessor($accessor),
        ];

        usort(
            $processors,
            function (ExifMetadataProcessorInterface $left, ExifMetadataProcessorInterface $right): int {
                return $this->readPriority($right) <=> $this->readPriority($left);
            }
        );

        return $processors;
    }

    private function readPriority(ExifMetadataProcessorInterface $processor): int
    {
        $reflection = new ReflectionClass($processor);

        foreach ($reflection->getAttributes(AutoconfigureTag::class) as $attribute) {
            $arguments = $attribute->getArguments();

            $name = $arguments[0] ?? ($arguments['name'] ?? null);
            if ($name !== 'memories.metadata.exif.processor') {
                continue;
            }

            $attributes = $arguments[1] ?? ($arguments['attributes'] ?? []);
            if (array_key_exists('priority', $attributes)) {
                return (int) $attributes['priority'];
            }

            return 0;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $exif
     */
    private function runProcessors(ExifMetadataExtractor $extractor, array $exif, Media $media): void
    {
        $reflection = new ReflectionProperty($extractor, 'processors');
        $reflection->setAccessible(true);

        $processors = $reflection->getValue($extractor);

        $method = new ReflectionMethod($extractor, 'normalizeUndefinedTagOffsets');
        $method->setAccessible(true);

        /** @var array<string, mixed> $normalized */
        $normalized = $method->invoke($extractor, $exif);

        foreach ($processors as $processor) {
            $processor->process($normalized, $media);
        }
    }
}
