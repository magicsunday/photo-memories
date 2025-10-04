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
use MagicSunday\Memories\Service\Metadata\Exif\Processor\AspectFlagExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\Exif\Processor\DimensionsExifMetadataProcessor;
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
                'Width' => $computedWidth,
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
        $processors = [
            new DimensionsExifMetadataProcessor(),
            new AspectFlagExifMetadataProcessor(),
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
