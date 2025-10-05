<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use function in_array;
use function strtolower;
use function trim;

/**
 * Provides helper methods to classify media formats.
 */
final class MediaFormatGuesser
{
    /** Raw file extensions used for detection. */
    private const array RAW_EXTENSIONS = ['cr2', 'cr3', 'nef', 'arw', 'rw2', 'raf', 'dng'];

    /** HEIC-related file extensions. */
    private const array HEIC_EXTENSIONS = ['heic', 'heif', 'hif'];

    /** HEVC-related file extensions. */
    private const array HEVC_EXTENSIONS = ['hevc', 'h265'];

    /** MIME types considered RAW. */
    private const array RAW_MIME_TYPES = [
        'image/x-canon-cr2',
        'image/x-canon-cr3',
        'image/x-nikon-nef',
        'image/x-sony-arw',
        'image/x-panasonic-rw2',
        'image/x-fuji-raf',
        'image/x-adobe-dng',
        'image/dng',
    ];

    /** MIME types representing HEIC media. */
    private const array HEIC_MIME_TYPES = [
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
    ];

    /** MIME types representing HEVC video. */
    private const array HEVC_MIME_TYPES = [
        'video/hevc',
        'video/h265',
    ];

    /** File type identifiers used to detect RAW media. */
    private const array RAW_FILE_TYPES = ['cr2', 'cr3', 'nef', 'arw', 'rw2', 'raf', 'dng', 'raw'];

    /** File type identifiers used to detect HEIC media. */
    private const array HEIC_FILE_TYPES = ['heic', 'heif'];

    /** File type identifiers used to detect HEVC media. */
    private const array HEVC_FILE_TYPES = ['hevc', 'h265'];

    public static function isRawFromExtension(?string $extension): bool
    {
        if ($extension === null) {
            return false;
        }

        $normalized = strtolower(trim($extension));

        return $normalized !== '' && in_array($normalized, self::RAW_EXTENSIONS, true);
    }

    public static function isHeicFromExtension(?string $extension): bool
    {
        if ($extension === null) {
            return false;
        }

        $normalized = strtolower(trim($extension));

        return $normalized !== '' && in_array($normalized, self::HEIC_EXTENSIONS, true);
    }

    public static function isHevcFromExtension(?string $extension): bool
    {
        if ($extension === null) {
            return false;
        }

        $normalized = strtolower(trim($extension));

        return $normalized !== '' && in_array($normalized, self::HEVC_EXTENSIONS, true);
    }

    public static function isRawFromMime(?string $mime): bool
    {
        if ($mime === null) {
            return false;
        }

        $normalized = strtolower(trim($mime));

        return $normalized !== '' && in_array($normalized, self::RAW_MIME_TYPES, true);
    }

    public static function isHeicFromMime(?string $mime): bool
    {
        if ($mime === null) {
            return false;
        }

        $normalized = strtolower(trim($mime));

        return $normalized !== '' && in_array($normalized, self::HEIC_MIME_TYPES, true);
    }

    public static function isHevcFromMime(?string $mime): bool
    {
        if ($mime === null) {
            return false;
        }

        $normalized = strtolower(trim($mime));

        return $normalized !== '' && in_array($normalized, self::HEVC_MIME_TYPES, true);
    }

    public static function isRawFromFileType(?string $fileType): bool
    {
        if ($fileType === null) {
            return false;
        }

        $normalized = strtolower(trim($fileType));

        return $normalized !== '' && in_array($normalized, self::RAW_FILE_TYPES, true);
    }

    public static function isHeicFromFileType(?string $fileType): bool
    {
        if ($fileType === null) {
            return false;
        }

        $normalized = strtolower(trim($fileType));

        return $normalized !== '' && in_array($normalized, self::HEIC_FILE_TYPES, true);
    }

    public static function isHevcFromFileType(?string $fileType): bool
    {
        if ($fileType === null) {
            return false;
        }

        $normalized = strtolower(trim($fileType));

        return $normalized !== '' && in_array($normalized, self::HEVC_FILE_TYPES, true);
    }

    public static function isHevcCodec(?string $codec): bool
    {
        if ($codec === null) {
            return false;
        }

        $normalized = strtolower(trim($codec));

        return $normalized !== '' && in_array($normalized, ['hevc', 'h265', 'h.265'], true);
    }
}
