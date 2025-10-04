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

    public function testNormalizeKeysAddsAliasesForObservedUndefinedTags(): void
    {
        $exif = [
            'EXIF' => [
                'UndefinedTag:0xA430' => 'Owner',
                'UndefinedTag:0xA431' => 'BodySerial',
                'UndefinedTag:0xA432' => ['24/1', '70/1', '0/1', '0/1'],
                'UndefinedTag:0xA433' => 'Lens Corp.',
                'UndefinedTag:0xA434' => 'Lens Model X',
                'UndefinedTag:0xA435' => 'LensSerial',
                'UndefinedTag:0xA460' => 2.2,
                'UndefinedTag:0xA461' => 1,
                'UndefinedTag:0xA462' => 2,
                'UndefinedTag:0xA463' => '1/200',
            ],
            'GPS' => [
                'UndefinedTag:0x001F' => 0.85,
            ],
        ];

        $normalized = DefaultExifValueAccessor::normalizeKeys($exif);

        self::assertSame('Owner', $normalized['EXIF']['CameraOwnerName']);
        self::assertSame('BodySerial', $normalized['EXIF']['BodySerialNumber']);
        self::assertSame(['24/1', '70/1', '0/1', '0/1'], $normalized['EXIF']['LensSpecification']);
        self::assertSame('Lens Corp.', $normalized['EXIF']['LensMake']);
        self::assertSame('Lens Model X', $normalized['EXIF']['LensModel']);
        self::assertSame('LensSerial', $normalized['EXIF']['LensSerialNumber']);
        self::assertSame(2.2, $normalized['EXIF']['Gamma']);
        self::assertSame(1, $normalized['EXIF']['CompositeImage']);
        self::assertSame(2, $normalized['EXIF']['SourceImageNumberOfCompositeImage']);
        self::assertSame('1/200', $normalized['EXIF']['SourceExposureTimesOfCompositeImage']);
        self::assertSame(0.85, $normalized['GPS']['GPSHPositioningError']);
    }
}
