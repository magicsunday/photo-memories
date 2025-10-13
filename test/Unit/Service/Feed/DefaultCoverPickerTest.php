<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Feed\DefaultCoverPicker;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

final class DefaultCoverPickerTest extends TestCase
{
    #[Test]
    public function rotatedPortraitMatchesUprightPortraitScore(): void
    {
        $picker = new DefaultCoverPicker();

        $uprightPortrait = $this->makeMedia(
            id: 101,
            path: '/fixtures/feed/upright.jpg',
            takenAt: '2024-08-01T12:00:00+00:00',
            size: 5_000_000,
            configure: static function (Media $media): void {
                $media->setWidth(3024);
                $media->setHeight(4032);
                $media->setOrientation(1);
                $media->setNeedsRotation(false);
                $media->setThumbnails(['default' => '/thumbs/upright.jpg']);
            },
        );

        $rotatedPortrait = $this->makeMedia(
            id: 102,
            path: '/fixtures/feed/rotated.jpg',
            takenAt: '2024-08-01T12:00:00+00:00',
            size: 5_000_000,
            configure: static function (Media $media): void {
                $media->setWidth(4032);
                $media->setHeight(3024);
                $media->setOrientation(6);
                $media->setNeedsRotation(true);
                $media->setThumbnails(['default' => '/thumbs/rotated.jpg']);
            },
        );

        $score = new ReflectionMethod(DefaultCoverPicker::class, 'score');
        $score->setAccessible(true);

        $uprightScore  = $score->invoke($picker, $uprightPortrait, null);
        $rotatedScore  = $score->invoke($picker, $rotatedPortrait, null);

        self::assertEqualsWithDelta($uprightScore, $rotatedScore, 1e-6);
    }
}
