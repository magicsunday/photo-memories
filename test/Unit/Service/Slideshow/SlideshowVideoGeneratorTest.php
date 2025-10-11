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
use RuntimeException;

use function array_map;
use function array_search;
use function count;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

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
        self::assertStringContainsString("scale=1920:1080:force_original_aspect_ratio=increase,zoompan=z='if(gte(iw/ih\\,1.778)\\,1.05+(1.15-1.05)*min(on/112\\,1)\\,1)'", $filterComplex);
        self::assertStringContainsString('s=1920x1080', $filterComplex);
        self::assertStringContainsString(':fps=30', $filterComplex);
        self::assertStringContainsString("crop='if(gte(iw/ih\\,1.778)\\,1920\\,iw)':'if(gte(iw/ih\\,1.778)\\,1080\\,ih)'", $filterComplex);
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
        self::assertStringContainsString('[2:a:0]afade=t=in:st=0:d=1.5,afade=t=out:st=6.5:d=1.5[aout]', $filterComplex);

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

        self::assertStringContainsString("zoompan=z='if(gte(iw/ih\\,1.778)\\,1.2+(1.3-1.2)*min(on/119\\,1)\\,1)'", $filterComplex);
        self::assertStringContainsString("x='if(gte(iw/ih\\,1.778)\\,clip((iw-(w/zoom))/2 + 0.4*(iw-(w/zoom))/2*min(on/119\\,1)\\,0\\,max(iw-(w/zoom)\\,0))\\,(iw-w)/2)'", $filterComplex);
        self::assertStringContainsString("y='if(gte(iw/ih\\,1.778)\\,clip((ih-(h/zoom))/2 + -0.25*(ih-(h/zoom))/2*min(on/119\\,1)\\,0\\,max(ih-(h/zoom)\\,0))\\,(ih-h)/2)'", $filterComplex);
        self::assertStringContainsString(':fps=30', $filterComplex);
        self::assertStringContainsString('s=1920x1080', $filterComplex);
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
            0.8,
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

        $job = new SlideshowJob(
            'durations',
            '/tmp/durations.job.json',
            '/tmp/durations.mp4',
            '/tmp/durations.lock',
            '/tmp/durations.error',
            ['/tmp/slide-1.jpg', '/tmp/slide-2.jpg', '/tmp/slide-3.jpg', '/tmp/slide-4.jpg'],
            $slides,
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

        self::assertSame(3, count($parsed['durations']));

        self::assertEqualsWithDelta(1.2, $parsed['durations'][0], 0.0001);
        self::assertEqualsWithDelta(0.25, $parsed['durations'][1], 0.0001);
        self::assertEqualsWithDelta(0.25, $parsed['durations'][2], 0.0001);

        self::assertSame(3, count($parsed['offsets']));
        self::assertEqualsWithDelta(3.0, $parsed['offsets'][0], 0.0001);
        self::assertEqualsWithDelta(6.0, $parsed['offsets'][1], 0.0001);
        self::assertEqualsWithDelta(6.25, $parsed['offsets'][2], 0.0001);
    }

    public function testEscapeFilterExpressionMasksEveryCommaExactlyOnce(): void
    {
        $generator = new SlideshowVideoGenerator();

        $reflector = new ReflectionClass($generator);
        $method    = $reflector->getMethod('escapeFilterExpression');
        $method->setAccessible(true);

        $expression = 'if(gte(iw/ih,1.778),1.05+(1.15-1.05)*min(t/4.3,1),1)';

        /** @var string $escaped */
        $escaped = $method->invoke($generator, $expression);

        self::assertSame(
            'if(gte(iw/ih\,1.778)\,1.05+(1.15-1.05)*min(t/4.3\,1)\,1)',
            $escaped,
        );

        self::assertSame(0, preg_match('/(?<!\\\\),/', $escaped));

        $preEscapedExpression = 'min(t/4.3\,1)';

        /** @var string $alreadyEscaped */
        $alreadyEscaped = $method->invoke($generator, $preEscapedExpression);

        self::assertSame($preEscapedExpression, $alreadyEscaped);
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
}
