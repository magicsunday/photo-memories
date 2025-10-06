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

use function is_file;
use function restore_error_handler;
use function set_error_handler;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const E_WARNING;

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

        $warnings = [];
        $handler  = static function (int $severity, string $message) use (&$warnings): bool {
            if ($severity === E_WARNING) {
                $warnings[] = $message;
            }

            return true;
        };

        set_error_handler($handler);

        try {
            $composite->extract($tmp, $media);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertNull($media->getMime());
    }
}
