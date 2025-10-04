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
use MagicSunday\Memories\Service\Metadata\Exif\Processor\DimensionsExifMetadataProcessor;
use MagicSunday\Memories\Service\Metadata\ExifMetadataExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
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

        $normalized = DefaultExifValueAccessor::normalizeKeys($exif);

        foreach ($processors as $processor) {
            $processor->process($normalized, $media);
        }
    }
}
