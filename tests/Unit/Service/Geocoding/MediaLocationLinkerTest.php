<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\MediaLocationLinker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MediaLocationLinkerTest extends TestCase
{
    public function testNormalizeAcceptLanguage(): void
    {
        $refClass = new ReflectionClass(MediaLocationLinker::class);
        $linker   = $refClass->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(MediaLocationLinker::class, 'normalizeAcceptLanguage');

        self::assertSame('de-DE', $method->invoke($linker, 'de_DE'));
        self::assertSame('de', $method->invoke($linker, ''));
        self::assertSame('fr', $method->invoke($linker, ' fr '));
        self::assertSame('de-DE', $method->invoke($linker, 'DE_de'));
        self::assertSame('de-DE', $method->invoke($linker, 'de_DE.UTF-8'));
        self::assertSame('de,en;q=0.7', $method->invoke($linker, 'de,en;q=0.7'));
    }
}
