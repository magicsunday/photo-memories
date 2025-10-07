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
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_encode;
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

        unlink($temporary);
    }
}
