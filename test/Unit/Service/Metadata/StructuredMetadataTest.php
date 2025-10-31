<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Service\Metadata\StructuredMetadata;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

final class StructuredMetadataTest extends TestCase
{
    #[Test]
    public function itNormalisesSectionValues(): void
    {
        $metadata = StructuredMetadata::fromArray([
            'lens' => [
                'make' => 'Canon',
                'serial' => 123456,
                'flags' => [true, false, 42, 'value', null, new stdClass()],
                'ignored' => new stdClass(),
            ],
            'camera' => [
                'owner' => null,
                'invalid' => new stdClass(),
            ],
            'image' => 'not-an-array',
        ]);

        self::assertSame('Canon', $metadata->lens->make);
        self::assertSame(123456, $metadata->lens->serial);
        self::assertSame([true, false, 42, 'value', null], $metadata->lens->flags);
        self::assertNull($metadata->lens->ignored);

        self::assertFalse($metadata->camera->isEmpty());
        self::assertSame(['owner' => null], $metadata->camera->toArray());
        self::assertTrue($metadata->image->isEmpty());
    }

    #[Test]
    public function itExportsToArray(): void
    {
        $metadata = StructuredMetadata::fromArray([
            'derived' => ['distance' => 0.5],
        ]);

        $payload = $metadata->toArray();

        self::assertArrayHasKey('lens', $payload);
        self::assertArrayHasKey('derived', $payload);
        self::assertSame([], $payload['lens']);
        self::assertSame(['distance' => 0.5], $payload['derived']);
    }
}
