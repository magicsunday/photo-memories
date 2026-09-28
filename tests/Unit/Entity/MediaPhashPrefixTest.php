<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Entity;

use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MediaPhashPrefixTest extends TestCase
{
    #[Test]
    public function truncatesPhashPrefixToThirtyTwoCharacters(): void
    {
        $media = $this->makeMedia(
            id: 201,
            path: '/library/phash-prefix.jpg',
        );

        $media->setPhashPrefix('0123456789abcdefcafebabe112233445566');

        self::assertSame('0123456789abcdefcafebabe11223344', $media->getPhashPrefix());
    }
}
