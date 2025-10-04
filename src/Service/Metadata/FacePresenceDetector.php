<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use InvalidArgumentException;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Support\FaceDetectionBackendInterface;
use MagicSunday\Memories\Service\Metadata\Support\VideoPosterFrameTrait;

use function is_file;
use function is_string;
use function str_starts_with;

/**
 * Detects faces on still images or poster frames extracted from videos.
 */
final class FacePresenceDetector implements SingleMetadataExtractorInterface
{
    use VideoPosterFrameTrait;

    private string $ffmpegBinary;

    private string $ffprobeBinary;

    private float $posterFrameSecond;

    public function __construct(
        private readonly FaceDetectionBackendInterface $backend,
        string $ffmpegBinary = 'ffmpeg',
        string $ffprobeBinary = 'ffprobe',
        float $posterFrameSecond = 1.0,
    ) {
        if ($posterFrameSecond < 0.0) {
            throw new InvalidArgumentException('posterFrameSecond must be greater or equal to zero.');
        }

        $this->ffmpegBinary      = $ffmpegBinary !== '' ? $ffmpegBinary : 'ffmpeg';
        $this->ffprobeBinary     = $ffprobeBinary !== '' ? $ffprobeBinary : 'ffprobe';
        $this->posterFrameSecond = $posterFrameSecond;
    }

    public function supports(string $filepath, Media $media): bool
    {
        if ($media->isNoShow() || $media->isLowQuality()) {
            return false;
        }

        if ($this->isVideoMedia($media)) {
            return true;
        }

        $mime = $media->getMime();

        return is_string($mime) && str_starts_with($mime, 'image/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        if (!$this->supports($filepath, $media)) {
            return $media;
        }

        if ($media->getFacesCount() > 0 || $media->hasFaces()) {
            return $media;
        }

        if ($media->getPersons() !== null) {
            return $media;
        }

        $sourcePath = $filepath;
        $posterPath = null;

        if ($this->isVideoMedia($media)) {
            $posterPath = $this->createPosterFrame($filepath);
            if ($posterPath === null) {
                return $media;
            }

            $sourcePath = $posterPath;
        }

        if (!is_file($sourcePath)) {
            $this->cleanupPosterFrame($posterPath);

            return $media;
        }

        try {
            $result = $this->backend->detectFaces($sourcePath, $media);
        } finally {
            $this->cleanupPosterFrame($posterPath);
        }

        if (!$result->isAvailable()) {
            return $media;
        }

        if ($media->getFacesCount() > 0 || $media->hasFaces()) {
            return $media;
        }

        $faces = $result->getFacesCount();

        if ($faces > 0) {
            $media->setHasFaces(true);
            $media->setFacesCount($faces);

            return $media;
        }

        $media->setHasFaces(false);
        $media->setFacesCount(0);

        return $media;
    }
}
