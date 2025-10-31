<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\StructuredMetadataFactory;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StructuredMetadataFactoryTest extends TestCase
{
    #[Test]
    public function itBuildsStructuredSectionsFromMedia(): void
    {
        $media = new Media('/tmp/sample.jpg', 'checksum', 42);
        $media->setLensMake('Canon');
        $media->setLensModel('RF 24-70mm');
        $media->setLensSpecification('24-70mm f/2.8L');
        $media->setLensSerialNumber('LENS-001');
        $media->setCameraMake('Canon');
        $media->setCameraModel('EOS R6');
        $media->setCameraOwner('Alex');
        $media->setCameraBodySerial('123456');
        $media->setWidth(4000);
        $media->setHeight(3000);
        $media->setOrientation(6);
        $media->setMime('image/jpeg');
        $media->setFocalLengthMm(35.0);
        $media->setFocalLength35mm(35);
        $media->setApertureF(2.8);
        $media->setExposureTimeS(0.01);
        $media->setIso(200);
        $media->setFlashFired(true);
        $media->setGpsLat(48.137154);
        $media->setGpsLon(11.576124);
        $media->setGpsAlt(520.5);
        $media->setGpsAccuracyM(4.2);
        $media->setGpsHeadingDeg(90.0);
        $media->setGpsSpeedMps(1.2);
        $media->setPhash('ABCDEF1234567890ABCDEF1234567890');
        $media->setPhash64('123456789');
        $media->setPhashPrefix('ABCDEF1234567890');
        $media->setDhash('FACE1234FACE1234');
        $media->setAhash('BEEF5678BEEF5678');
        $media->setTakenAt(new DateTimeImmutable('2024-10-05T12:34:56+00:00'));
        $media->setCapturedLocal(new DateTimeImmutable('2024-10-05T14:34:56+02:00'));
        $media->setTzId('Europe/Berlin');
        $media->setTimezoneOffsetMin(120);
        $media->setTimeSource(TimeSource::EXIF);
        $media->setDistanceKmFromHome(12.5);
        $media->setHomeConfigHash('home_v2');
        $media->setContentKind(ContentKind::PHOTO);
        $media->setVideoDurationS(12.34);
        $media->setVideoFps(29.97);
        $media->setIsSlowMo(false);

        $factory = new StructuredMetadataFactory();
        $metadata = $factory->create($media);

        self::assertSame('Canon RF 24-70mm 24-70mm f/2.8L', $metadata->lens->summary);
        self::assertSame('Canon EOS R6', $metadata->camera->summary);
        self::assertSame('4000 × 3000', $metadata->image->dimensions);
        self::assertSame('90° gedreht', $metadata->image->orientation_label);
        self::assertSame('35 mm', $metadata->exposure->focal_length_text);
        self::assertSame('f/2.8', $metadata->exposure->aperture_text);
        self::assertSame('1/100 s', $metadata->exposure->exposure_text);
        self::assertSame('Blitz ausgelöst', $metadata->exposure->flash_text);
        self::assertSame('48.137154, 11.576124', $metadata->gps->coordinates);
        self::assertSame('abcdef1234567890abcdef1234567890', $metadata->preview->phash);
        self::assertSame('2024-10-05T12:34:56+00:00', $metadata->derived->taken_at);
        self::assertSame('Europe/Berlin', $metadata->derived->timezone);
        self::assertSame('photo', $metadata->derived->content_kind);
        self::assertSame(12.5, $metadata->derived->distance_km_from_home);
    }
}
