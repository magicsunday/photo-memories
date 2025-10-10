<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Slideshow;

use MagicSunday\Memories\Service\Slideshow\SlideshowJob;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_search;
use function count;
use function preg_match;

/**
 * @covers \MagicSunday\Memories\Service\Slideshow\SlideshowVideoGenerator
 */
final class SlideshowVideoGeneratorTest extends TestCase
{
    public function testSubtitleMetadataUsesProvidedSubtitle(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('appendAudioOptions');
        $method->setAccessible(true);

        $command = $method->invoke(
            $generator,
            ['ffmpeg'],
            1,
            '/tmp/out.mp4',
            null,
            'Ein Tag am Meer',
            '01.02.2024 – 14.02.2024',
        );

        $metadataEntries = [];
        $commandLength   = count($command);
        for ($index = 0; $index < $commandLength; ++$index) {
            if ($command[$index] !== '-metadata') {
                continue;
            }

            $valueIndex = $index + 1;
            if ($valueIndex < $commandLength) {
                $metadataEntries[] = $command[$valueIndex];
            }
        }

        self::assertContains('subtitle=01.02.2024 – 14.02.2024', $metadataEntries);
    }

    public function testTitleOverlayIsAppliedOnlyToFirstSlide(): void
    {
        $slides = [
            [
                'image'      => '/tmp/cover.jpg',
                'mediaId'    => 1,
                'duration'   => 3.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/second.jpg',
                'mediaId'    => 2,
                'duration'   => 3.0,
                'transition' => null,
            ],
        ];

        $job = new SlideshowJob(
            'example',
            '/tmp/example.json',
            '/tmp/out.mp4',
            '/tmp/out.lock',
            '/tmp/out.error',
            ['/tmp/cover.jpg', '/tmp/second.jpg'],
            $slides,
            null,
            null,
            'Rückblick',
            '01.01.2024 – 31.01.2024'
        );

        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildCommand');
        $method->setAccessible(true);

        /** @var list<string> $command */
        $command = $method->invoke($generator, $job, $job->slides());

        $filterIndex = array_search('-filter_complex', $command, true);
        self::assertNotFalse($filterIndex);

        $filterComplexIndex = $filterIndex + 1;
        self::assertArrayHasKey($filterComplexIndex, $command);

        $filterComplex = $command[$filterComplexIndex];
        self::assertStringContainsString('[0:v]split=2[bg0][fg0]', $filterComplex);
        self::assertStringContainsString('gblur=sigma=', $filterComplex);
        self::assertStringContainsString('zoompan=z=', $filterComplex);
        self::assertStringContainsString("scale=-1:720,zoompan=z='1'", $filterComplex);
        self::assertStringContainsString("crop=if(gte(iw/ih,1.778),1280,iw):if(gte(iw/ih,1.778),720,ih)", $filterComplex);
        self::assertStringContainsString('[bg0out][fg0out]overlay=(main_w-overlay_w)/2:(main_h-overlay_h)/2', $filterComplex);
        self::assertStringContainsString('[bg1out][fg1out]overlay=(main_w-overlay_w)/2:(main_h-overlay_h)/2', $filterComplex);
        self::assertStringNotContainsString('pad=', $filterComplex);
        self::assertStringContainsString("drawtext=text='Rückblick'", $filterComplex);
        self::assertStringContainsString("drawtext=text='01.01.2024 – 31.01.2024'", $filterComplex);
        self::assertStringNotContainsString('[vtmp]', $filterComplex);
        self::assertSame(
            0,
            preg_match('/\\[1:v][^;\\[]*drawtext/', $filterComplex),
            'Overlay should only appear on the cover slide.'
        );
    }

    public function testZoompanExpressionsRespectConfiguredValues(): void
    {
        $slides = [
            [
                'image'      => '/tmp/cover.jpg',
                'mediaId'    => null,
                'duration'   => 4.0,
                'transition' => null,
            ],
        ];

        $job = new SlideshowJob(
            'ken-burns',
            '/tmp/example.json',
            '/tmp/out.mp4',
            '/tmp/out.lock',
            '/tmp/out.error',
            ['/tmp/cover.jpg'],
            $slides,
            null,
            null,
            null,
            null,
        );

        $generator = new SlideshowVideoGenerator(
            kenBurnsEnabled: true,
            zoomStart: 1.2,
            zoomEnd: 1.3,
            panX: 0.4,
            panY: -0.25,
        );

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildCommand');
        $method->setAccessible(true);

        /** @var list<string> $command */
        $command = $method->invoke($generator, $job, $job->slides());

        $filterIndex = array_search('-filter_complex', $command, true);
        self::assertNotFalse($filterIndex);

        $filterComplexIndex = $filterIndex + 1;
        self::assertArrayHasKey($filterComplexIndex, $command);

        $filterComplex = $command[$filterComplexIndex];

        self::assertStringContainsString("zoompan=z='if(gte(iw/ih,1.778),1.2+(1.3-1.2)*min(PTS/", $filterComplex);
        self::assertStringContainsString("x='if(gte(iw/ih,1.778),clip((iw-zoom*w)/2 + 0.4*(iw-zoom*w)/2*min(PTS/", $filterComplex);
        self::assertStringContainsString("y='if(gte(iw/ih,1.778),clip((ih-zoom*h)/2 + -0.25*(ih-zoom*h)/2*min(PTS/", $filterComplex);
        self::assertStringContainsString("s=if(gte(iw/ih,1.778),1280,iw):if(gte(iw/ih,1.778),720,ih)", $filterComplex);
    }
}
