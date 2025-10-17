<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Http;

use MagicSunday\Memories\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testCreateNormalizesNumericQueryValuesToStrings(): void
    {
        $request = Request::create('/api/feed', 'GET', ['score' => 0.35, 'limit' => 24]);

        self::assertSame('0.35', $request->getQueryParam('score'));
        self::assertSame('24', $request->getQueryParam('limit'));
    }

    public function testCreateIgnoresNonScalarQueryValues(): void
    {
        $request = Request::create('/api/feed', 'GET', ['payload' => ['nested' => 'value']]);

        self::assertNull($request->getQueryParam('payload'));
    }

    public function testCreateNormalizesStringableQueryValues(): void
    {
        $request = Request::create('/api/feed', 'GET', [
            'cursor' => new class implements \Stringable {
                public function __toString(): string
                {
                    return 'time:123';
                }
            },
        ]);

        self::assertSame('time:123', $request->getQueryParam('cursor'));
    }
}
