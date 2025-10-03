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
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

final class SlideshowJobTest extends TestCase
{
    #[Test]
    public function itSerializesToJsonAndBack(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'slideshow-job-');
        self::assertIsString($tmp);

        $job = new SlideshowJob('abc', $tmp, '/tmp/out.mp4', '/tmp/out.mp4.lock', '/tmp/out.mp4.error', ['/path/one.jpg']);
        file_put_contents($tmp, $job->toJson(), LOCK_EX);

        $loaded = SlideshowJob::fromJsonFile($tmp);

        self::assertSame('abc', $loaded->id());
        self::assertSame('/tmp/out.mp4', $loaded->outputPath());
        self::assertSame(['/path/one.jpg'], $loaded->images());

        unlink($tmp);
    }

    #[Test]
    public function itRejectsJobsWithoutImages(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'slideshow-job-');
        self::assertIsString($tmp);

        file_put_contents(
            $tmp,
            json_encode(
                [
                    'id' => 'abc',
                    'output' => '/tmp/out.mp4',
                    'lock' => '/tmp/out.mp4.lock',
                    'error' => '/tmp/out.mp4.error',
                    'images' => [],
                ],
                JSON_THROW_ON_ERROR
            ),
            LOCK_EX
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Job payload does not contain any usable images.');

        try {
            SlideshowJob::fromJsonFile($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
