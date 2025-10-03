<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Slideshow;

use MagicSunday\Memories\Service\Slideshow\SlideshowVideoStatus;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SlideshowVideoStatusTest extends TestCase
{
    #[Test]
    public function itSerializesReadyStatus(): void
    {
        $status = SlideshowVideoStatus::ready('/api/feed/foo/video', 3.45);
        self::assertSame(
            [
                'status' => SlideshowVideoStatus::STATUS_READY,
                'meldung' => null,
                'dauerProBildSekunden' => 3.45,
                'url' => '/api/feed/foo/video',
            ],
            $status->toArray()
        );
        self::assertSame(SlideshowVideoStatus::STATUS_READY, $status->status());
    }

    #[Test]
    public function itSerializesGeneratingStatus(): void
    {
        $status = SlideshowVideoStatus::generating(2.0);
        self::assertSame(
            [
                'status' => SlideshowVideoStatus::STATUS_GENERATING,
                'meldung' => 'Video wird erstellt …',
                'dauerProBildSekunden' => 2.0,
            ],
            $status->toArray()
        );
    }

    #[Test]
    public function itSerializesErrorStatus(): void
    {
        $status = SlideshowVideoStatus::error('Fehler', 1.25);
        self::assertSame(
            [
                'status' => SlideshowVideoStatus::STATUS_ERROR,
                'meldung' => 'Fehler',
                'dauerProBildSekunden' => 1.25,
            ],
            $status->toArray()
        );
    }
}
