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
use MagicSunday\Memories\Service\Slideshow\TransitionSequenceGenerator;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * @covers \MagicSunday\Memories\Service\Slideshow\SlideshowJob
 */
final class SlideshowJobTest extends TestCase
{
    public function testToArrayContainsStoryboardInformation(): void
    {
        $job = new SlideshowJob(
            'demo',
            '/tmp/demo.job.json',
            '/tmp/demo.mp4',
            '/tmp/demo.lock',
            '/tmp/demo.error',
            ['/images/1.jpg', '/images/2.jpg'],
            [
                [
                    'image'      => '/images/1.jpg',
                    'mediaId'    => 10,
                    'duration'   => 3.0,
                    'transition' => 'fade',
                ],
                [
                    'image'      => '/images/2.jpg',
                    'mediaId'    => 11,
                    'duration'   => 4.0,
                    'transition' => null,
                ],
            ],
            0.75,
            '/audio/theme.mp3',
            'Ein Tag am Meer',
            'Sommer 2024',
        );

        $payload = $job->toArray();

        self::assertSame('demo', $payload['id']);
        self::assertSame('/tmp/demo.mp4', $payload['output']);
        self::assertArrayHasKey('storyboard', $payload);
        self::assertSame(0.75, $payload['storyboard']['transitionDuration']);
        self::assertSame('/audio/theme.mp3', $payload['storyboard']['music']);
        self::assertCount(2, $payload['storyboard']['slides']);
        self::assertSame(10, $payload['storyboard']['slides'][0]['mediaId']);
        self::assertSame(3.0, $payload['storyboard']['slides'][0]['duration']);
        self::assertSame('fade', $payload['storyboard']['slides'][0]['transition']);
        self::assertSame('Ein Tag am Meer', $payload['title']);
        self::assertSame('Sommer 2024', $payload['subtitle']);
    }

    public function testFromJsonFileNormalisesIncompleteStoryboard(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'slideshow-job-');
        self::assertIsString($temporary);

        $payload = [
            'id'         => 'example',
            'output'     => '/out/example.mp4',
            'lock'       => '/out/example.lock',
            'error'      => '/out/example.error',
            'images'     => ['/images/a.jpg', '/images/b.jpg'],
            'storyboard' => [
                'slides' => [
                    [
                        'image'    => '/images/a.jpg',
                        'mediaId'  => '15',
                        'duration' => 0,
                        'transition' => '  ',
                    ],
                    [
                        'image' => '/images/b.jpg',
                    ],
                ],
                'transitionDuration' => 0,
                'music'              => ' ',
            ],
            'title'     => '  ',
            'subtitle'  => 'Erinnerungen ',
        ];

        file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR));

        $job = SlideshowJob::fromJsonFile($temporary);

        self::assertSame('example', $job->id());
        self::assertSame(['/images/a.jpg', '/images/b.jpg'], $job->images());

        $slides = $job->slides();
        self::assertCount(2, $slides);
        self::assertSame(15, $slides[0]['mediaId']);
        self::assertNull($job->transitionDuration());
        self::assertNull($job->audioTrack());
        self::assertNull($job->title());
        self::assertSame('Erinnerungen', $job->subtitle());

        unlink($temporary);
    }

    public function testToJsonPersistsTransitionOrder(): void
    {
        $transitions = TransitionSequenceGenerator::generate(
            ['fade', 'dissolve', 'fadeblack', 'radial'],
            [10, 11, 12, 13],
            4
        );

        $slides = [];
        foreach ($transitions as $index => $transition) {
            $slides[] = [
                'image'      => sprintf('/images/%d.jpg', $index + 1),
                'mediaId'    => 10 + $index,
                'duration'   => 3.5,
                'transition' => $transition,
            ];
        }

        $job = new SlideshowJob(
            'persist-transitions',
            '/tmp/transitions.job.json',
            '/tmp/transitions.mp4',
            '/tmp/transitions.lock',
            '/tmp/transitions.error',
            array_map(static fn (array $slide): string => $slide['image'], $slides),
            $slides,
            0.75,
            null,
            null,
            null,
        );

        $json = $job->toJson();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('storyboard', $payload);
        self::assertIsArray($payload['storyboard']);
        self::assertArrayHasKey('slides', $payload['storyboard']);

        /** @var list<array<string, mixed>> $jsonSlides */
        $jsonSlides = $payload['storyboard']['slides'];
        self::assertCount(4, $jsonSlides);

        foreach ($transitions as $index => $transition) {
            self::assertSame($transition, $jsonSlides[$index]['transition']);
        }
    }
}
