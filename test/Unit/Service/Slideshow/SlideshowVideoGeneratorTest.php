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
use MagicSunday\Memories\Service\Slideshow\SlideshowTransitionCache;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function array_fill_keys;
use function array_map;
use function array_search;
use function chmod;
use function base64_decode;
use function count;
use function explode;
use function file_put_contents;
use function hash;
use function implode;
use function preg_match;
use function preg_match_all;
use function round;
use function sprintf;
use function strpos;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * @covers \MagicSunday\Memories\Service\Slideshow\SlideshowVideoGenerator
 */
final class SlideshowVideoGeneratorTest extends TestCase
{
    public function testCommentMetadataUsesProvidedSubtitle(): void
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
            0.0,
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

        self::assertContains('comment=01.02.2024 – 14.02.2024', $metadataEntries);
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
            [],
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
        self::assertStringContainsString('scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,gblur=sigma=', $filterComplex);
        self::assertStringContainsString(',gblur=sigma=20[bg0out]', $filterComplex);
        self::assertStringContainsString("zoompan=z='max(1\\,1+(1.08-1)*min(on/90\\,1))'", $filterComplex);
        self::assertStringNotContainsString('min(on/112,1)', $filterComplex);
        self::assertStringContainsString(':fps=30', $filterComplex);
        self::assertStringContainsString(':fps=30,scale=ceil(iw/2)*2:ceil(ih/2)*2', $filterComplex);
        self::assertStringContainsString('scale=ceil(iw/2)*2:ceil(ih/2)*2', $filterComplex);
        self::assertStringNotContainsString('s=ceil(iw/2)*2xceil(ih/2)*2', $filterComplex);
        self::assertStringNotContainsString('s=1920x1080', $filterComplex);
        self::assertStringContainsString('[bg0out][fg0out]overlay=(main_w-overlay_w)/2:(main_h-overlay_h)/2', $filterComplex);
        self::assertStringContainsString('[bg1out][fg1out]overlay=(main_w-overlay_w)/2:(main_h-overlay_h)/2', $filterComplex);
        self::assertStringContainsString("drawtext=text='Rückblick'", $filterComplex);
        self::assertStringContainsString("drawtext=text='01.01.2024 – 31.01.2024'", $filterComplex);
        self::assertStringContainsString('x=w*0.07', $filterComplex);
        self::assertStringContainsString('h*0.07', $filterComplex);
        self::assertStringNotContainsString('safeX', $filterComplex);
        self::assertStringNotContainsString('safeY', $filterComplex);
        self::assertStringNotContainsString('[vtmp]', $filterComplex);
        self::assertSame(
            0,
            preg_match('/\\[1:v][^;\\[]*drawtext/', $filterComplex),
            'Overlay should only appear on the cover slide.'
        );
    }

    public function testBuildCommandBuildsCorrectFilterChain(): void
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
            'filter-chain',
            '/tmp/filter-chain.json',
            '/tmp/filter-chain.mp4',
            '/tmp/filter-chain.lock',
            '/tmp/filter-chain.error',
            ['/tmp/cover.jpg', '/tmp/second.jpg'],
            $slides,
            [],
            null,
            null,
            null,
            null,
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

        self::assertStringContainsString('zoompan=z=', $filterComplex);
        self::assertStringContainsString(':fps=30,scale=ceil(iw/2)*2:ceil(ih/2)*2', $filterComplex);
        self::assertStringContainsString('scale=ceil(iw/2)*2:ceil(ih/2)*2', $filterComplex);
        self::assertStringNotContainsString('s=ceil(iw/2)*2xceil(ih/2)*2', $filterComplex);
        self::assertStringNotContainsString('s=1920x1080', $filterComplex);
    }

    public function testBlurredSlideFilterIncludesFrameCountInZoompan(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildBlurredSlideFilter');
        $method->setAccessible(true);

        $slide = [
            'image'      => '/tmp/example.jpg',
            'mediaId'    => 1,
            'duration'   => 3.0,
            'transition' => null,
        ];

        /** @var string $filter */
        $filter = $method->invoke(
            $generator,
            0,
            3.0,
            3.0,
            $slide,
            null,
            null,
        );

        $expectedFrameCount = (int) round(3.0 * 30);

        self::assertStringContainsString(
            sprintf(':d=%d:fps=30', $expectedFrameCount),
            $filter,
        );
    }

    public function testBuildIntroTextOverlayFilterChainPlacesSubtitleAboveTitle(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildIntroTextOverlayFilterChain');
        $method->setAccessible(true);

        /** @var string $filters */
        $filters = $method->invoke($generator, 'Rückblick', '01.01.2024 – 31.01.2024');

        $parts = explode(',', $filters);

        self::assertCount(2, $parts);

        $subtitleFilter = $parts[0];
        $titleFilter    = $parts[1];

        self::assertStringContainsString("drawtext=text='01.01.2024 – 31.01.2024'", $subtitleFilter);
        self::assertStringContainsString('fontsize=h*0.038', $subtitleFilter);
        self::assertStringContainsString('x=w*0.07', $subtitleFilter);
        self::assertStringContainsString('y=h*0.86', $subtitleFilter);
        self::assertStringContainsString('shadowcolor=black@0.25:shadowx=0:shadowy=6:borderw=2:bordercolor=black@0.20', $subtitleFilter);

        self::assertStringContainsString("drawtext=text='Rückblick'", $titleFilter);
        self::assertStringContainsString('fontsize=h*0.060', $titleFilter);
        self::assertStringContainsString('x=w*0.07', $titleFilter);
        self::assertStringContainsString('y=h*0.92', $titleFilter);
        self::assertStringContainsString('shadowcolor=black@0.25:shadowx=0:shadowy=6:borderw=2:bordercolor=black@0.20', $titleFilter);

        self::assertStringNotContainsString('safeX', $filters);
        self::assertStringNotContainsString('safeY', $filters);
    }

    public function testBuildIntroTextOverlayFilterChainPlacesSingleTitleAtSafeArea(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildIntroTextOverlayFilterChain');
        $method->setAccessible(true);

        /** @var string $filters */
        $filters = $method->invoke($generator, 'Rückblick', null);

        self::assertStringContainsString("drawtext=text='Rückblick'", $filters);
        self::assertStringContainsString('x=w*0.07', $filters);
        self::assertStringContainsString('fontsize=h*0.060', $filters);
        self::assertStringContainsString('y=h*0.92', $filters);
        self::assertStringContainsString('shadowcolor=black@0.25:shadowx=0:shadowy=6:borderw=2:bordercolor=black@0.20', $filters);
        self::assertStringNotContainsString('safeX', $filters);
        self::assertStringNotContainsString('safeY', $filters);
    }

    public function testBuildIntroTextOverlayFilterChainMatchesExpectedStructure(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);

        $method = $reflector->getMethod('buildIntroTextOverlayFilterChain');
        $method->setAccessible(true);

        $escapeMethod = $reflector->getMethod('escapeDrawTextValue');
        $escapeMethod->setAccessible(true);

        $title    = "Sommer's Rückblick";
        $subtitle = 'Intro: 01%';

        /** @var string $filters */
        $filters = $method->invoke($generator, $title, $subtitle);

        /** @var string $escapedSubtitle */
        $escapedSubtitle = trim($escapeMethod->invoke($generator, $subtitle));

        /** @var string $escapedTitle */
        $escapedTitle = trim($escapeMethod->invoke($generator, $title));

        $expected = sprintf(
            "drawtext=text='%s':fontcolor=white:fontsize=h*0.038:shadowcolor=black@0.25:shadowx=0:shadowy=6:borderw=2:bordercolor=black@0.20:x=w*0.07:y=h*0.86,drawtext=text='%s':fontcolor=white:fontsize=h*0.060:shadowcolor=black@0.25:shadowx=0:shadowy=6:borderw=2:bordercolor=black@0.20:x=w*0.07:y=h*0.92",
            $escapedSubtitle,
            $escapedTitle
        );

        self::assertSame($expected, $filters);
    }

    public function testEscapeDrawTextValueEscapesLineBreaks(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('escapeDrawTextValue');
        $method->setAccessible(true);

        $value   = "Rückblick\n2024\r\nAbspann";
        $escaped = $method->invoke($generator, $value);

        self::assertSame('Rückblick\\n2024\\r\\nAbspann', $escaped);
        self::assertStringContainsString('\\n', $escaped);
        self::assertStringContainsString('\\r', $escaped);
        self::assertStringNotContainsString("\n", $escaped);
        self::assertStringNotContainsString("\r", $escaped);
    }

    public function testBuildCommandUsesFourSecondCoverClipWhenDurationMissing(): void
    {
        $slides = [
            [
                'image'      => '/tmp/cover.jpg',
                'mediaId'    => null,
                'duration'   => 0.0,
                'transition' => null,
            ],
        ];

        $job = new SlideshowJob(
            'missing-duration-cover',
            '/tmp/example.json',
            '/tmp/out.mp4',
            '/tmp/out.lock',
            '/tmp/out.error',
            ['/tmp/cover.jpg'],
            $slides,
            [],
            null,
            null,
            null,
            null,
        );

        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildCommand');
        $method->setAccessible(true);

        /** @var list<string> $command */
        $command = $method->invoke($generator, $job, $job->slides());

        $durationIndex = array_search('-t', $command, true);
        self::assertNotFalse($durationIndex);

        $durationValueIndex = $durationIndex + 1;
        self::assertArrayHasKey($durationValueIndex, $command);
        self::assertSame('4.000', $command[$durationValueIndex]);
    }

    public function testBackgroundBlurEnableExpressionCoversNarrowLandscapeSlides(): void
    {
        $portraitImage       = $this->createTemporaryImage('iVBORw0KGgoAAAANSUhEUgAAAAEAAAACCAIAAAAW4yFwAAAADklEQVR4nGP4z8DAAMQACf4B/4PiLjgAAAAASUVORK5CYII=');
        $narrowLandscapeImage = $this->createTemporaryImage('iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgIAoAAAAnAAGfWjwcAAAAAElFTkSuQmCC');
        $wideLandscapeImage   = $this->createTemporaryImage('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAADUlEQVR4nGNgYPgPRAAFAgH/wSuWnwAAAABJRU5ErkJggg==');

        try {
            $slides = [
                [
                    'image'      => $portraitImage,
                    'mediaId'    => 1,
                    'duration'   => 3.0,
                    'transition' => null,
                ],
                [
                    'image'      => $narrowLandscapeImage,
                    'mediaId'    => 2,
                    'duration'   => 3.0,
                    'transition' => null,
                ],
                [
                    'image'      => $wideLandscapeImage,
                    'mediaId'    => 3,
                    'duration'   => 3.0,
                    'transition' => null,
                ],
            ];

            $job = new SlideshowJob(
                'orientation',
                '/tmp/example.json',
                '/tmp/out.mp4',
                '/tmp/out.lock',
                '/tmp/out.error',
                [$portraitImage, $narrowLandscapeImage, $wideLandscapeImage],
                $slides,
                [],
                null,
                null,
                null,
                null,
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

            self::assertSame(1, preg_match('/\\[bg0\]([^;\[]+)\\[bg0out\]/', $filterComplex, $portraitMatch));
            self::assertSame(1, preg_match('/\\[bg1\]([^;\[]+)\\[bg1out\]/', $filterComplex, $narrowLandscapeMatch));
            self::assertSame(1, preg_match('/\\[bg2\]([^;\[]+)\\[bg2out\]/', $filterComplex, $wideLandscapeMatch));

            $expectedScale = 'scale=1920:1080:force_original_aspect_ratio=increase';
            $expectedBlur  = 'gblur=sigma=20';
            $expectedCrop  = ',crop=1920:1080';

            foreach ([$portraitMatch[1], $narrowLandscapeMatch[1], $wideLandscapeMatch[1]] as $backgroundFilter) {
                self::assertStringContainsString($expectedScale, $backgroundFilter);
                self::assertStringContainsString($expectedBlur, $backgroundFilter);
                self::assertStringContainsString($expectedCrop, $backgroundFilter);

                $scalePosition = strpos($backgroundFilter, $expectedScale);
                $blurPosition  = strpos($backgroundFilter, $expectedBlur);
                $cropPosition  = strpos($backgroundFilter, $expectedCrop);

                self::assertNotFalse($scalePosition);
                self::assertNotFalse($blurPosition);
                self::assertNotFalse($cropPosition);
                self::assertGreaterThan($scalePosition, $blurPosition);
                self::assertGreaterThan($cropPosition, $blurPosition);
            }

            self::assertStringContainsString($expectedBlur, $narrowLandscapeMatch[1]);
        } finally {
            @unlink($portraitImage);
            @unlink($narrowLandscapeImage);
            @unlink($wideLandscapeImage);
        }
    }

    public function testPortraitSlideFilterUsesConditionalZoomAndSubtlePan(): void
    {
        $portraitImage = $this->createTemporaryImage('iVBORw0KGgoAAAANSUhEUgAAAAEAAAACCAIAAAAW4yFwAAAADklEQVR4nGP4z8DAAMQACf4B/4PiLjgAAAAASUVORK5CYII=');

        try {
            $slides = [
                [
                    'image'      => $portraitImage,
                    'mediaId'    => 1,
                    'duration'   => 3.0,
                    'transition' => null,
                ],
            ];

            $job = new SlideshowJob(
                'portrait-ken-burns',
                '/tmp/example.json',
                '/tmp/out.mp4',
                '/tmp/out.lock',
                '/tmp/out.error',
                [$portraitImage],
                $slides,
                [],
                null,
                null,
                null,
                null,
            );

            $generator = new SlideshowVideoGenerator(
                kenBurnsEnabled: true,
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

            self::assertStringContainsString("zoompan=z='max(1\\,1+(1.08-1)*min(on/90\\,1))'", $filterComplex);
            $panXMatch = [];
            self::assertSame(
                1,
                preg_match(
                    '#clip\(\(iw-\(iw/zoom\)\)/2 \+ (?P<panX>-?\d+\.\d+)\*#',
                    $filterComplex,
                    $panXMatch,
                ),
            );

            $panYMatch = [];
            self::assertSame(
                1,
                preg_match(
                    '#clip\(\(ih-\(ih/zoom\)\)/2 \+ (?P<panY>-?\d+\.\d+)\*#',
                    $filterComplex,
                    $panYMatch,
                ),
            );

            $panX = (float) $panXMatch['panX'];
            $panY = (float) $panYMatch['panY'];

            self::assertGreaterThanOrEqual(-0.05, $panX);
            self::assertLessThanOrEqual(0.05, $panX);
            self::assertGreaterThanOrEqual(-0.05, $panY);
            self::assertLessThanOrEqual(0.05, $panY);
        } finally {
            @unlink($portraitImage);
        }
    }

    public function testKenBurnsAlternatesZoomDirectionForSubsequentSlides(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('resolveKenBurnsParameters');
        $method->setAccessible(true);

        $firstSlide = [
            'image'      => '/tmp/first.jpg',
            'mediaId'    => null,
            'duration'   => 3.0,
            'transition' => null,
        ];

        $secondSlide = [
            'image'      => '/tmp/second.jpg',
            'mediaId'    => null,
            'duration'   => 3.0,
            'transition' => null,
        ];

        $title    = 'Sommerferien';
        $subtitle = 'Tag 1';

        /** @var array{zoomStart: float, zoomEnd: float, panX: float, panY: float} $first */
        $first = $method->invoke($generator, 0, $firstSlide, $title, $subtitle);
        /** @var array{zoomStart: float, zoomEnd: float, panX: float, panY: float} $second */
        $second = $method->invoke($generator, 1, $secondSlide, $title, $subtitle);
        /** @var array{zoomStart: float, zoomEnd: float, panX: float, panY: float} $secondRepeat */
        $secondRepeat = $method->invoke($generator, 1, $secondSlide, $title, $subtitle);

        self::assertSame(1.0, $first['zoomStart']);
        self::assertSame(1.08, $first['zoomEnd']);
        self::assertSame(1.08, $second['zoomStart']);
        self::assertSame(1.0, $second['zoomEnd']);
        self::assertGreaterThanOrEqual(-0.05, $first['panX']);
        self::assertLessThanOrEqual(0.05, $first['panX']);
        self::assertGreaterThanOrEqual(-0.05, $second['panX']);
        self::assertLessThanOrEqual(0.05, $second['panX']);
        self::assertGreaterThanOrEqual(-0.05, $first['panY']);
        self::assertLessThanOrEqual(0.05, $first['panY']);
        self::assertGreaterThanOrEqual(-0.05, $second['panY']);
        self::assertLessThanOrEqual(0.05, $second['panY']);
        self::assertSame($second['panX'], $secondRepeat['panX']);
        self::assertSame($second['panY'], $secondRepeat['panY']);
    }

    public function testKenBurnsPanOffsetsRemainDeterministicWithinRange(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('resolveKenBurnsParameters');
        $method->setAccessible(true);

        $title    = 'Abenteuerurlaub';
        $subtitle = 'Kapitel 3';

        $firstSlide = [
            'image'      => '/tmp/first-deterministic.jpg',
            'mediaId'    => null,
            'duration'   => 4.0,
            'transition' => null,
        ];

        $secondSlide = [
            'image'      => '/tmp/second-deterministic.jpg',
            'mediaId'    => null,
            'duration'   => 5.0,
            'transition' => null,
        ];

        /** @var array{zoomStart: float, zoomEnd: float, panX: float, panY: float} $first */
        $first = $method->invoke($generator, 0, $firstSlide, $title, $subtitle);
        /** @var array{zoomStart: float, zoomEnd: float, panX: float, panY: float} $firstRepeat */
        $firstRepeat = $method->invoke($generator, 0, $firstSlide, $title, $subtitle);
        /** @var array{zoomStart: float, zoomEnd: float, panX: float, panY: float} $second */
        $second = $method->invoke($generator, 1, $secondSlide, $title, $subtitle);
        /** @var array{zoomStart: float, zoomEnd: float, panX: float, panY: float} $secondRepeat */
        $secondRepeat = $method->invoke($generator, 1, $secondSlide, $title, $subtitle);

        self::assertSame($first, $firstRepeat);

        $panLimit = 0.05;

        self::assertGreaterThanOrEqual(-$panLimit, $first['panX']);
        self::assertLessThanOrEqual($panLimit, $first['panX']);
        self::assertGreaterThanOrEqual(-$panLimit, $first['panY']);
        self::assertLessThanOrEqual($panLimit, $first['panY']);
        self::assertGreaterThanOrEqual(-$panLimit, $second['panX']);
        self::assertLessThanOrEqual($panLimit, $second['panX']);
        self::assertGreaterThanOrEqual(-$panLimit, $second['panY']);
        self::assertLessThanOrEqual($panLimit, $second['panY']);

        self::assertSame($first['panX'], $firstRepeat['panX']);
        self::assertSame($first['panY'], $firstRepeat['panY']);
        self::assertSame($second['panX'], $secondRepeat['panX']);
        self::assertSame($second['panY'], $secondRepeat['panY']);
    }

    private function createTemporaryImage(string $base64): string
    {
        $path = sprintf('%s/slideshow-%s.png', sys_get_temp_dir(), uniqid('', true));
        $data = base64_decode($base64, true);
        self::assertNotFalse($data);

        $bytesWritten = file_put_contents($path, $data);
        self::assertNotFalse($bytesWritten);

        return $path;
    }

    public function testAudioFadeIsConfiguredWhenAudioTrackExceedsThreshold(): void
    {
        $slides = [
            [
                'image'      => '/tmp/cover.jpg',
                'mediaId'    => 1,
                'duration'   => 4.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/second.jpg',
                'mediaId'    => 2,
                'duration'   => 4.0,
                'transition' => null,
            ],
        ];

        $job = new SlideshowJob(
            'audio-fade',
            '/tmp/example.json',
            '/tmp/out.mp4',
            '/tmp/out.lock',
            '/tmp/out.error',
            ['/tmp/cover.jpg', '/tmp/second.jpg'],
            $slides,
            [],
            null,
            '/tmp/music.mp3',
            null,
            null,
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
        self::assertStringContainsString('[2:a:0]afade=t=in:st=0:d=1.5,afade=t=out:st=5.656:d=1.5[aout]', $filterComplex);

        $audioMapIndex = array_search('-map', $command, true);
        self::assertNotFalse($audioMapIndex);

        $audioLabelIndex = null;
        $commandLength   = count($command);
        for ($index = 0; $index < $commandLength; ++$index) {
            if ($command[$index] !== '-map') {
                continue;
            }

            $candidateIndex = $index + 1;
            if ($candidateIndex < $commandLength && $command[$candidateIndex] === '[aout]') {
                $audioLabelIndex = $candidateIndex;
                break;
            }
        }

        self::assertNotNull($audioLabelIndex);
    }

    public function testTimelineOffsetsAndAudioFadeAreAlignedForTransitions(): void
    {
        $slides = [
            [
                'image'      => '/tmp/cover.jpg',
                'mediaId'    => 1,
                'duration'   => 4.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/second.jpg',
                'mediaId'    => 2,
                'duration'   => 5.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/third.jpg',
                'mediaId'    => 3,
                'duration'   => 6.0,
                'transition' => null,
            ],
        ];

        $transitionDuration = 1.0;
        $customTransitions  = [0.5, 2.0];

        $job = new SlideshowJob(
            'timeline-audio-fade',
            '/tmp/example.json',
            '/tmp/out.mp4',
            '/tmp/out.lock',
            '/tmp/out.error',
            ['/tmp/cover.jpg', '/tmp/second.jpg', '/tmp/third.jpg'],
            $slides,
            $customTransitions,
            $transitionDuration,
            '/tmp/music.mp3',
            null,
            null,
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

        $resolvedTransitionsMethod = $reflector->getMethod('resolveTransitionDurationsForSlides');
        $resolvedTransitionsMethod->setAccessible(true);
        /** @var list<float> $resolvedTransitions */
        $resolvedTransitions = $resolvedTransitionsMethod->invoke(
            $generator,
            $job->slides(),
            $job->transitionDurations(),
            $job->title(),
            $job->subtitle(),
        );

        $loopDurations = [];
        for ($index = 0; $index < $filterIndex; ++$index) {
            if ($command[$index] !== '-t') {
                continue;
            }

            $valueIndex = $index + 1;
            if ($valueIndex < $filterIndex) {
                $loopDurations[] = (float) $command[$valueIndex];
            }
        }

        $expectedLoopCount = count($slides);
        self::assertCount($expectedLoopCount, $loopDurations);
        $lastSlideIndex    = $expectedLoopCount - 1;
        $lastSlideDuration = $slides[$lastSlideIndex]['duration'];
        self::assertEqualsWithDelta($lastSlideDuration, $loopDurations[$lastSlideIndex], 0.001);

        $trimMatchCount = preg_match_all('/trim=duration=([0-9.]+)/', $filterComplex, $trimMatches);
        self::assertSame($expectedLoopCount, $trimMatchCount);
        $trimDurations = array_map('floatval', $trimMatches[1]);
        self::assertEqualsWithDelta($lastSlideDuration, $trimDurations[$lastSlideIndex], 0.001);

        $matchCount = preg_match_all('/xfade=[^:]+:duration=([0-9.]+):offset=([0-9.]+)/', $filterComplex, $matches);
        self::assertSame(count($resolvedTransitions), $matchCount);
        $transitionDurations = array_map('floatval', $matches[1]);
        foreach ($transitionDurations as $index => $duration) {
            self::assertEqualsWithDelta($resolvedTransitions[$index], $duration, 0.001);
        }

        $offsets = array_map('floatval', $matches[2]);
        self::assertCount(count($resolvedTransitions), $offsets);

        $expectedTimeline = max(2.5, $slides[0]['duration']);
        $expectedOffsets  = [];
        $slideCount       = count($slides);

        for ($index = 1; $index < $slideCount; ++$index) {
            $overlap          = $resolvedTransitions[$index - 1] ?? $transitionDuration;
            $expectedOffsets[] = $expectedTimeline - $overlap;
            $expectedTimeline += $slides[$index]['duration'] - $overlap;
        }

        foreach ($offsets as $index => $offset) {
            self::assertEqualsWithDelta($expectedOffsets[$index], $offset, 0.001);
        }

        $lastOffset = $offsets[count($offsets) - 1];
        self::assertEqualsWithDelta($expectedTimeline, $lastOffset + $lastSlideDuration, 0.001);

        $fadeMatchPattern = '/afade=t=out:st=([0-9.]+):d=1\\.5/';
        self::assertSame(1, preg_match($fadeMatchPattern, $filterComplex, $fadeMatches));

        $fadeStart            = (float) $fadeMatches[1];
        $expectedFadeDuration = 1.5;
        $expectedFadeStart    = max(0.0, $expectedTimeline - $expectedFadeDuration);

        self::assertEqualsWithDelta($expectedFadeStart, $fadeStart, 0.001);
    }

    public function testDeterministicTransitionDurationsAreWithinExpectedRange(): void
    {
        $slides = [
            [
                'image'      => '/tmp/slide-a.jpg',
                'mediaId'    => 1,
                'duration'   => 4.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/slide-b.jpg',
                'mediaId'    => 2,
                'duration'   => 5.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/slide-c.jpg',
                'mediaId'    => 3,
                'duration'   => 6.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/slide-d.jpg',
                'mediaId'    => 4,
                'duration'   => 5.5,
                'transition' => null,
            ],
        ];

        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('resolveTransitionDurationsForSlides');
        $method->setAccessible(true);

        /** @var list<float> $first */
        $first = $method->invoke($generator, $slides, [], 'Gipfelmomente', 'Winter 2024');
        /** @var list<float> $second */
        $second = $method->invoke($generator, $slides, [], 'Gipfelmomente', 'Winter 2024');

        self::assertSame($first, $second, 'Deterministic seeds should produce stable durations.');
        self::assertCount(3, $first);

        $unique = array_unique(array_map(static fn (float $value): string => sprintf('%.6F', $value), $first));
        self::assertGreaterThan(1, count($unique), 'Expected at least two distinct transition durations.');

        foreach ($first as $duration) {
            self::assertGreaterThanOrEqual(0.6, $duration);
            self::assertLessThanOrEqual(1.0, $duration);
        }
    }

    public function testGenerateThrowsExceptionWhenImageFileIsNotReadable(): void
    {
        $missingImage = sys_get_temp_dir() . '/missing-' . uniqid('', true) . '.jpg';

        $slides = [
            [
                'image'      => $missingImage,
                'mediaId'    => 1,
                'duration'   => 3.0,
                'transition' => null,
            ],
        ];

        $job = new SlideshowJob(
            'unreadable-image',
            sys_get_temp_dir() . '/job.json',
            sys_get_temp_dir() . '/slideshow.mp4',
            sys_get_temp_dir() . '/slideshow.lock',
            sys_get_temp_dir() . '/slideshow.error',
            [$missingImage],
            $slides,
            [],
            null,
            null,
            null,
            null,
        );

        $generator = new SlideshowVideoGenerator();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Slideshow image file "%s" is not readable.', $missingImage));

        $generator->generate($job);
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
            [],
            null,
            null,
            null,
            null,
        );

        $generator = new SlideshowVideoGenerator(
            kenBurnsEnabled: true,
            zoomStart: 1.2,
            zoomEnd: 1.3,
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

        self::assertStringContainsString("zoompan=z='max(1\\,1.2+(1.3-1.2)*min(on/120\\,1))'", $filterComplex);
        $panXMatch = [];
        self::assertSame(
            1,
            preg_match(
                '#clip\(\(iw-\(iw/zoom\)\)/2 \+ (?P<panX>-?\d+\.\d+)\*#',
                $filterComplex,
                $panXMatch,
            ),
        );

        $panYMatch = [];
        self::assertSame(
            1,
            preg_match(
                '#clip\(\(ih-\(ih/zoom\)\)/2 \+ (?P<panY>-?\d+\.\d+)\*#',
                $filterComplex,
                $panYMatch,
            ),
        );

        $panX = (float) $panXMatch['panX'];
        $panY = (float) $panYMatch['panY'];

        self::assertGreaterThanOrEqual(-0.05, $panX);
        self::assertLessThanOrEqual(0.05, $panX);
        self::assertGreaterThanOrEqual(-0.05, $panY);
        self::assertLessThanOrEqual(0.05, $panY);
        self::assertStringContainsString(':fps=30', $filterComplex);
        self::assertStringContainsString('scale=ceil(iw/2)*2:ceil(ih/2)*2', $filterComplex);
        self::assertStringNotContainsString('s=ceil(iw/2)*2xceil(ih/2)*2', $filterComplex);
    }

    public function testBuildCommandUsesStoryboardTransitions(): void
    {
        $slides = [
            [
                'image'      => '/tmp/first.jpg',
                'mediaId'    => 1,
                'duration'   => 3.0,
                'transition' => ' pixelize ',
            ],
            [
                'image'      => '/tmp/second.jpg',
                'mediaId'    => 2,
                'duration'   => 3.0,
                'transition' => '   ',
            ],
            [
                'image'      => '/tmp/third.jpg',
                'mediaId'    => 3,
                'duration'   => 3.0,
                'transition' => null,
            ],
        ];

        $job = new SlideshowJob(
            'transitions',
            '/tmp/transitions.job.json',
            '/tmp/transitions.mp4',
            '/tmp/transitions.lock',
            '/tmp/transitions.error',
            ['/tmp/first.jpg', '/tmp/second.jpg', '/tmp/third.jpg'],
            $slides,
            [0.75, 0.75],
            0.75,
            null,
            null,
            null,
        );

        $generator = new SlideshowVideoGenerator(transitions: ['wiperight']);

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildCommand');
        $method->setAccessible(true);

        /** @var list<string> $command */
        $command = $method->invoke($generator, $job, $job->slides());

        $filterIndex = array_search('-filter_complex', $command, true);
        self::assertNotFalse($filterIndex);

        $filterComplex = $command[$filterIndex + 1];
        self::assertStringContainsString('xfade=transition=pixelize:duration=', $filterComplex);
        self::assertStringContainsString('xfade=transition=wiperight:duration=', $filterComplex);
    }

    public function testDiscoveredTransitionsAreFilteredAgainstWhitelist(): void
    {
        $reflector = new ReflectionClass(SlideshowVideoGenerator::class);
        $resetCacheMethod = $reflector->getMethod('resetTransitionCache');
        $resetCacheMethod->setAccessible(true);
        $resetCacheMethod->invoke(null);

        $script = sprintf('%s/ffmpeg-%s', sys_get_temp_dir(), uniqid('slideshow-', true));

        $helpOutput = <<<'OUTPUT'
XFades transitions help
  Possible transitions:
    fade            Fade transition
    sparkle         Experimental sparkle effect
    circleopen      Circle opening wipe
    wipeleft        Wipe from the left
    zoom            Zoom effect
OUTPUT;

        $scriptContent = <<<BASH
#!/usr/bin/env bash
cat <<'EOF'
$helpOutput
EOF
BASH;

        file_put_contents($script, $scriptContent);
        chmod($script, 0755);

        $generator = new SlideshowVideoGenerator(ffmpegBinary: $script);

        $whitelistMethod = $reflector->getMethod('getTransitionWhitelist');
        $whitelistMethod->setAccessible(true);

        $lookupMethod = $reflector->getMethod('getTransitionLookup');
        $lookupMethod->setAccessible(true);

        try {
            /** @var list<string> $whitelist */
            $whitelist = $whitelistMethod->invoke($generator);

            self::assertSame(
                ['fade', 'wipeleft', 'circleopen'],
                $whitelist,
            );

            /** @var array<string, bool> $lookup */
            $lookup = $lookupMethod->invoke($generator);

            self::assertSame(
                [
                    'fade' => true,
                    'wipeleft' => true,
                    'circleopen' => true,
                ],
                $lookup,
            );
        } finally {
            $resetCacheMethod->invoke(null);
            unlink($script);
        }
    }

    public function testDeterministicFallbackTransitionsUseWhitelist(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);

        $defaultsProperty = $reflector->getProperty('transitions');
        $defaultsProperty->setAccessible(true);

        /** @var list<string> $defaults */
        $defaults = $defaultsProperty->getValue($generator);

        /** @var list<string> $whitelist */
        $whitelist = $reflector->getConstant('TRANSITION_WHITELIST');

        self::assertSame(
            [
                'fade',
                'dissolve',
                'fadeblack',
                'fadewhite',
                'wipeleft',
                'wiperight',
                'wipeup',
                'wipedown',
                'slideleft',
                'slideright',
                'smoothleft',
                'smoothright',
                'circleopen',
                'circleclose',
                'radial',
                'hlslice',
                'vuslice',
                'distance',
                'pixelize',
            ],
            $whitelist,
        );

        self::assertSame($whitelist, $defaults);

        $filterMethod = $reflector->getMethod('filterAllowedTransitions');
        $filterMethod->setAccessible(true);

        /** @var list<string> $filtered */
        $filtered = $filterMethod->invoke(
            $generator,
            [' fade ', 'invalid', '', 'pixelize', 'wipedown', 'hlslice', 'Distance', 'Vertopen']
        );

        self::assertSame(['fade', 'pixelize', 'wipedown', 'hlslice', 'distance'], $filtered);

        $buildMethod = $reflector->getMethod('buildDeterministicTransitionSequence');
        $buildMethod->setAccessible(true);

        $images = ['/tmp/a.jpg', '/tmp/b.jpg', '/tmp/c.jpg', '/tmp/d.jpg'];

        /** @var list<string> $first */
        $first = $buildMethod->invoke($generator, $filtered, $images, 'Alpha', 'Bravo', 3);
        /** @var list<string> $second */
        $second = $buildMethod->invoke($generator, $filtered, $images, 'Alpha', 'Bravo', 3);
        /** @var list<string> $different */
        $different = $buildMethod->invoke($generator, $filtered, $images, 'Alpha', 'Charlie', 3);

        self::assertSame($first, $second);
        self::assertCount(3, $first);

        foreach ($first as $index => $transition) {
            self::assertContains($transition, $filtered);

            if ($index > 0 && count($filtered) > 1) {
                self::assertNotSame($first[$index - 1], $transition);
            }
        }

        self::assertNotSame($first, $different);
    }

    public function testTransitionDurationsAreClampedAndShortened(): void
    {
        $slides = [
            [
                'image'      => '/tmp/slide-1.jpg',
                'mediaId'    => 1,
                'duration'   => 3.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/slide-2.jpg',
                'mediaId'    => 2,
                'duration'   => 3.0,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/slide-3.jpg',
                'mediaId'    => 3,
                'duration'   => 0.25,
                'transition' => null,
            ],
            [
                'image'      => '/tmp/slide-4.jpg',
                'mediaId'    => 4,
                'duration'   => 3.0,
                'transition' => null,
            ],
        ];

        $customDurations = [2.0, 0.5, 1.0];

        $job = new SlideshowJob(
            'durations',
            '/tmp/durations.job.json',
            '/tmp/durations.mp4',
            '/tmp/durations.lock',
            '/tmp/durations.error',
            ['/tmp/slide-1.jpg', '/tmp/slide-2.jpg', '/tmp/slide-3.jpg', '/tmp/slide-4.jpg'],
            $slides,
            $customDurations,
            5.0,
            null,
            'Test',
            'Alpha',
        );

        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('buildCommand');
        $method->setAccessible(true);

        /** @var list<string> $command */
        $command = $method->invoke($generator, $job, $job->slides());

        $parsed = $this->parseTransitionsFromCommand($command);

        $resolvedTransitionsMethod = $reflector->getMethod('resolveTransitionDurationsForSlides');
        $resolvedTransitionsMethod->setAccessible(true);
        /** @var list<float> $resolvedTransitions */
        $resolvedTransitions = $resolvedTransitionsMethod->invoke(
            $generator,
            $job->slides(),
            $job->transitionDurations(),
            $job->title(),
            $job->subtitle(),
        );

        self::assertSame(3, count($parsed['durations']));

        self::assertCount(3, $resolvedTransitions);
        foreach ($resolvedTransitions as $index => $duration) {
            self::assertEqualsWithDelta($duration, $parsed['durations'][$index], 0.0001);
        }

        self::assertEqualsWithDelta(1.2, $parsed['durations'][0], 0.0001);
        self::assertEqualsWithDelta(0.25, $parsed['durations'][1], 0.0001);
        self::assertEqualsWithDelta(0.25, $parsed['durations'][2], 0.0001);

        self::assertSame(3, count($parsed['offsets']));
        self::assertEqualsWithDelta(1.8, $parsed['offsets'][0], 0.0001);
        self::assertEqualsWithDelta(4.55, $parsed['offsets'][1], 0.0001);
        self::assertEqualsWithDelta(4.55, $parsed['offsets'][2], 0.0001);
    }

    public function testEscapeFilterExpressionMasksEveryCommaExactlyOnce(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('escapeFilterExpression');
        $method->setAccessible(true);

        $expression = 'if(gte(iw/ih,1.778),1+(1.08-1)*min(t/4.3,1),1)';

        /** @var string $escaped */
        $escaped = $method->invoke($generator, $expression);

        self::assertSame(
            'if(gte(iw/ih\,1.778)\,1+(1.08-1)*min(t/4.3\,1)\,1)',
            $escaped,
        );

        self::assertSame(0, preg_match('/(?<!\\\\),/', $escaped));

        $preEscapedExpression = 'min(t/4.3\,1)';

        /** @var string $alreadyEscaped */
        $alreadyEscaped = $method->invoke($generator, $preEscapedExpression);

        self::assertSame($preEscapedExpression, $alreadyEscaped);
    }

    public function testParseXfadeHelpOutputExtractsTransitionNames(): void
    {
        $this->resetTransitionCache();

        $generator = new SlideshowVideoGenerator();

        $helpOutput = <<<'HELP'
Filter xfade
  cross fade between two inputs

    transition           <string>     set transition name (default "fade")
        possible transitions:
            fade             simple cross fade
            fadeblack        fade via black
            fadewhite        fade via white
        possible transitions: smoothleft, smoothright
        available transitions:
            slideleft        slide to the left
            circleopen       circle opening wipe
HELP;

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('parseXfadeHelpOutput');
        $method->setAccessible(true);

        /** @var list<string> $transitions */
        $transitions = $method->invoke($generator, $helpOutput);

        self::assertSame(
            ['fade', 'fadeblack', 'fadewhite', 'smoothleft', 'smoothright', 'slideleft', 'circleopen'],
            $transitions,
        );
    }

    public function testTransitionLookupIsRebuiltWhenWhitelistChanges(): void
    {
        $this->resetTransitionCache();

        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);

        $cacheMethod = $reflector->getMethod('transitionCache');
        $cacheMethod->setAccessible(true);

        /** @var SlideshowTransitionCache $cache */
        $cache = $cacheMethod->invoke(null);

        $initialWhitelist = ['fade'];
        $initialKey       = hash('sha256', implode('|', $initialWhitelist));

        $cache->whitelist = $initialWhitelist;
        $cache->lookup    = array_fill_keys($initialWhitelist, true);
        $cache->lookupKey = $initialKey;

        $lookupMethod = $reflector->getMethod('getTransitionLookup');
        $lookupMethod->setAccessible(true);

        /** @var array<string, bool> $initialLookup */
        $initialLookup = $lookupMethod->invoke($generator);

        self::assertSame($cache->lookup, $initialLookup);
        self::assertSame($initialKey, $cache->lookupKey);

        $updatedWhitelist = ['fade', 'wipeleft'];

        $cache->whitelist = $updatedWhitelist;
        $cache->lookup    = $initialLookup;
        $cache->lookupKey = $initialKey;

        /** @var array<string, bool> $updatedLookup */
        $updatedLookup = $lookupMethod->invoke($generator);

        self::assertSame(array_fill_keys($updatedWhitelist, true), $updatedLookup);

        $expectedKey = hash('sha256', implode('|', $updatedWhitelist));
        self::assertSame($expectedKey, $cache->lookupKey);
    }

    public function testTransitionDiscoveryFallsBackToWhitelistWhenCommandFails(): void
    {
        $this->resetTransitionCache();

        $generator = new SlideshowVideoGenerator(ffmpegBinary: '/path/to/missing/ffmpeg');

        $reflector = new ReflectionClass($generator);

        $method = $reflector->getMethod('getTransitionWhitelist');
        $method->setAccessible(true);

        /** @var list<string> $transitions */
        $transitions = $method->invoke($generator);

        $constant = $reflector->getReflectionConstant('TRANSITION_WHITELIST');
        self::assertNotFalse($constant);

        self::assertSame($constant->getValue(), $transitions);

        /** @var list<string> $cached */
        $cached = $method->invoke($generator);
        self::assertSame($transitions, $cached, 'Transitions should be cached across invocations.');
    }

    /**
     * @param list<string> $command
     *
     * @return array{transitions:list<string>,durations:list<float>,offsets:list<float>}
     */
    private function parseTransitionsFromCommand(array $command): array
    {
        $filterIndex = array_search('-filter_complex', $command, true);
        self::assertNotFalse($filterIndex);

        $filterComplexIndex = $filterIndex + 1;
        self::assertArrayHasKey($filterComplexIndex, $command);

        $filterComplex = $command[$filterComplexIndex];

        $matches = [];
        preg_match_all('/xfade=transition=([^:]+):duration=([0-9.]+):offset=([0-9.]+)/', $filterComplex, $matches);

        /** @var list<string> $transitions */
        $transitions = $matches[1] ?? [];
        /** @var list<float> $durations */
        $durations = array_map(static fn (string $value): float => (float) $value, $matches[2] ?? []);
        /** @var list<float> $offsets */
        $offsets = array_map(static fn (string $value): float => (float) $value, $matches[3] ?? []);

        return [
            'transitions' => $transitions,
            'durations'   => $durations,
            'offsets'     => $offsets,
        ];
    }

    private function resetTransitionCache(): void
    {
        $reflector = new ReflectionClass(SlideshowVideoGenerator::class);

        $method = $reflector->getMethod('resetTransitionCache');
        $method->setAccessible(true);
        $method->invoke(null);
    }
}
