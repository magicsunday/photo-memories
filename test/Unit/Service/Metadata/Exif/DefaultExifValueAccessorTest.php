<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata\Exif;

use MagicSunday\Memories\Service\Metadata\Exif\DefaultExifValueAccessor;
use PHPUnit\Framework\TestCase;

final class DefaultExifValueAccessorTest extends TestCase
{
    public function testParseOffsetMinutesAcceptsColonSeparatedValue(): void
    {
        $accessor = new DefaultExifValueAccessor();

        $result = $accessor->parseOffsetMinutes([
            'EXIF' => ['OffsetTimeOriginal' => '+02:00'],
        ]);

        self::assertSame(120, $result);
    }

    public function testParseOffsetMinutesHandlesColonlessValue(): void
    {
        $accessor = new DefaultExifValueAccessor();

        $result = $accessor->parseOffsetMinutes([
            'EXIF' => ['OffsetTimeOriginal' => '0130'],
        ]);

        self::assertSame(90, $result);
    }

    public function testParseOffsetMinutesTrimsWhitespaceAndFallbacks(): void
    {
        $accessor = new DefaultExifValueAccessor();

        $result = $accessor->parseOffsetMinutes([
            'EXIF' => [
                'OffsetTimeOriginal' => '   ',
                'OffsetTime'        => " -0530\0",
            ],
        ]);

        self::assertSame(-330, $result);
    }

    public function testParseOffsetMinutesSupportsZuluSuffix(): void
    {
        $accessor = new DefaultExifValueAccessor();

        $result = $accessor->parseOffsetMinutes([
            'EXIF' => ['OffsetTimeOriginal' => 'Z'],
        ]);

        self::assertSame(0, $result);
    }

    public function testParseOffsetMinutesRejectsSubMinutePrecision(): void
    {
        $accessor = new DefaultExifValueAccessor();

        $result = $accessor->parseOffsetMinutes([
            'EXIF' => ['OffsetTimeOriginal' => '+01:15:30'],
        ]);

        self::assertNull($result);
    }
}
