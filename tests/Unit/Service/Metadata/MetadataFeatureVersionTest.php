<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Service\Metadata\Feature\MetadataFeatureMigrationInterface;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MetadataFeatureVersionTest extends TestCase
{
    #[Test]
    public function returnsDefaultNamespaceVersion(): void
    {
        MetadataFeatureVersion::reset();

        self::assertSame(1, MetadataFeatureVersion::namespaceVersion('calendar'));
        self::assertNull(MetadataFeatureVersion::namespaceMigration('calendar'));
    }

    #[Test]
    public function allowsRegisteringCustomNamespace(): void
    {
        MetadataFeatureVersion::reset();

        MetadataFeatureVersion::registerNamespace('vision', 3, DummyMigration::class);

        self::assertSame(3, MetadataFeatureVersion::namespaceVersion('vision'));
        self::assertSame(DummyMigration::class, MetadataFeatureVersion::namespaceMigration('vision'));
    }

    protected function tearDown(): void
    {
        MetadataFeatureVersion::reset();
    }
}

final class DummyMigration implements MetadataFeatureMigrationInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function migrate(array $payload): array
    {
        return $payload;
    }
}
