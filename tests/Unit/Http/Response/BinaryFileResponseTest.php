<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Http\Response;

use MagicSunday\Memories\Http\Response\BinaryFileResponse;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function file_get_contents;

final class BinaryFileResponseTest extends TestCase
{
    #[Test]
    public function mapsCssExtensionToTextCssMimeType(): void
    {
        $file = $this->fixturePath('assets/sample.css');

        $response = new BinaryFileResponse($file);
        $headers  = $response->getHeaders();

        self::assertSame('text/css', $headers['Content-Type'] ?? null);
    }

    #[Test]
    public function lazilyReadsBinaryContent(): void
    {
        $file = $this->fixturePath('assets/sample.css');

        $response = new BinaryFileResponse($file);

        $expected = file_get_contents($file);

        self::assertIsString($expected);
        self::assertSame($expected, $response->getContent());
        self::assertSame($expected, $response->getContent());
    }
}
