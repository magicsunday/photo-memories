<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\FacePresenceDetector;
use MagicSunday\Memories\Service\Metadata\Support\FaceDetectionBackendInterface;
use MagicSunday\Memories\Service\Metadata\Support\FaceDetectionResult;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class FacePresenceDetectorTest extends TestCase
{
    #[Test]
    public function detectsFacesOnStillImageWhenMetadataMissing(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'face_');
        if ($imagePath === false) {
            self::fail('Unable to create temporary image fixture.');
        }

        file_put_contents($imagePath, 'jpeg');

        $backend  = new RecordingFaceDetectionBackend(FaceDetectionResult::fromCount(2));
        $detector = new FacePresenceDetector($backend);

        try {
            $media = $this->makeMedia(
                id: 701,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/jpeg');
                    $media->setWidth(1024);
                    $media->setHeight(768);
                },
            );

            $detector->extract($imagePath, $media);

            self::assertSame(1, $backend->calls);
            self::assertSame($imagePath, $backend->lastPath);
            self::assertTrue($media->hasFaces());
            self::assertSame(2, $media->getFacesCount());
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    #[Test]
    public function marksAbsenceWhenBackendReturnsZero(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'face_');
        if ($imagePath === false) {
            self::fail('Unable to create temporary image fixture.');
        }

        file_put_contents($imagePath, 'jpeg');

        $backend  = new RecordingFaceDetectionBackend(FaceDetectionResult::fromCount(0));
        $detector = new FacePresenceDetector($backend);

        try {
            $media = $this->makeMedia(
                id: 702,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/jpeg');
                    $media->setWidth(800);
                    $media->setHeight(600);
                },
            );

            $detector->extract($imagePath, $media);

            self::assertSame(1, $backend->calls);
            self::assertFalse($media->hasFaces());
            self::assertSame(0, $media->getFacesCount());
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    #[Test]
    public function skipsWhenMediaAlreadyContainsFaceMetadata(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'face_');
        if ($imagePath === false) {
            self::fail('Unable to create temporary image fixture.');
        }

        file_put_contents($imagePath, 'jpeg');

        $backend  = new RecordingFaceDetectionBackend(FaceDetectionResult::fromCount(4));
        $detector = new FacePresenceDetector($backend);

        try {
            $media = $this->makeMedia(
                id: 703,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/jpeg');
                    $media->setWidth(640);
                    $media->setHeight(480);
                    $media->setHasFaces(true);
                    $media->setFacesCount(3);
                },
            );

            $detector->extract($imagePath, $media);

            self::assertSame(0, $backend->calls);
            self::assertTrue($media->hasFaces());
            self::assertSame(3, $media->getFacesCount());
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    #[Test]
    public function skipsWhenMediaIsHiddenOrLowQuality(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'face_');
        if ($imagePath === false) {
            self::fail('Unable to create temporary image fixture.');
        }

        file_put_contents($imagePath, 'jpeg');

        $backend  = new RecordingFaceDetectionBackend(FaceDetectionResult::fromCount(1));
        $detector = new FacePresenceDetector($backend);

        try {
            $media = $this->makeMedia(
                id: 704,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/jpeg');
                    $media->setWidth(640);
                    $media->setHeight(480);
                    $media->setLowQuality(true);
                },
            );

            $detector->extract($imagePath, $media);
            self::assertSame(0, $backend->calls);

            $media->setLowQuality(false);
            $media->setNoShow(true);

            $detector->extract($imagePath, $media);
            self::assertSame(0, $backend->calls);
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }
}

final class RecordingFaceDetectionBackend implements FaceDetectionBackendInterface
{
    public int $calls = 0;

    public ?string $lastPath = null;

    public function __construct(private readonly FaceDetectionResult $result)
    {
    }

    public function detectFaces(string $imagePath, Media $media): FaceDetectionResult
    {
        ++$this->calls;
        $this->lastPath = $imagePath;

        return $this->result;
    }
}
