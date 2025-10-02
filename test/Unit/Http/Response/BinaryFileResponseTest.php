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

final class BinaryFileResponseTest extends TestCase
{
    #[Test]
    public function mapsCssExtensionToTextCssMimeType(): void
    {
        $file = $this->fixturePath('assets/sample.css');

        $response = new BinaryFileResponse($file);
        $result   = $response->send();

        self::assertSame('text/css', $result['headers']['Content-Type'] ?? null);
    }
}
