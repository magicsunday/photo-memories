<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Service\Metadata\CompositeMetadataExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function chmod;
use function file_put_contents;
use function is_file;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class CompositeMetadataExtractorTest extends TestCase
{
    #[Test]
    public function skipsMimeGuessWhenFileDoesNotExist(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'missing_media_');
        if ($tmp === false) {
            self::fail('Unable to allocate temporary filename.');
        }

        if (is_file($tmp) && @unlink($tmp) === false) {
            self::fail('Unable to remove temporary placeholder.');
        }

        $media = $this->makeMedia(
            id: 101,
            path: $tmp,
            checksum: str_repeat('0', 64),
            size: 512,
        );

        $composite = new CompositeMetadataExtractor([]);

        $composite->extract($tmp, $media);

        self::assertNull($media->getMime());
        self::assertSame('MIME-Bestimmung übersprungen: Datei nicht gefunden.', $media->getIndexLog());
    }

    #[Test]
    public function guessMimeSetsMimeAndLogsSuccess(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'media_mime_success_');
        if ($tmp === false) {
            self::fail('Unable to allocate temporary filename.');
        }

        $bytesWritten = file_put_contents($tmp, 'plain-text');
        if ($bytesWritten === false) {
            self::fail('Unable to populate temporary file.');
        }

        $media = $this->makeMedia(
            id: 102,
            path: $tmp,
            checksum: str_repeat('1', 64),
            size: 512,
        );

        $composite = new CompositeMetadataExtractor([]);

        $composite->extract($tmp, $media);

        self::assertSame('text/plain', $media->getMime());
        self::assertSame('MIME-Bestimmung erfolgreich: text/plain', $media->getIndexLog());

        if (is_file($tmp) && unlink($tmp) === false) {
            self::fail('Unable to clean up temporary file.');
        }
    }

    #[Test]
    public function guessMimeLogsFailureWhenFileIsUnreadable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'media_mime_failure_');
        if ($tmp === false) {
            self::fail('Unable to allocate temporary filename.');
        }

        $bytesWritten = file_put_contents($tmp, 'locked');
        if ($bytesWritten === false) {
            self::fail('Unable to populate temporary file.');
        }

        if (chmod($tmp, 0) === false) {
            if (is_file($tmp) && unlink($tmp) === false) {
                self::fail('Unable to clean up temporary file after chmod failure.');
            }

            self::markTestSkipped('Filesystem permissions cannot be adjusted in this environment.');
        }

        $media = $this->makeMedia(
            id: 103,
            path: $tmp,
            checksum: str_repeat('2', 64),
            size: 512,
        );

        $composite = new CompositeMetadataExtractor([]);

        try {
            $composite->extract($tmp, $media);
        } finally {
            if (chmod($tmp, 0o644) === false) {
                self::fail('Unable to restore permissions for cleanup.');
            }

            if (is_file($tmp) && unlink($tmp) === false) {
                self::fail('Unable to clean up temporary file.');
            }
        }

        self::assertNull($media->getMime());
        self::assertNotNull($media->getIndexLog());
        self::assertStringContainsString('MIME-Bestimmung fehlgeschlagen', $media->getIndexLog());
    }
}
