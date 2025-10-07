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
use MagicSunday\Memories\Service\Metadata\Feature\MetadataFeatureMigrationInterface;

/**
 * Central definition of the metadata feature schema version.
 */
final class MetadataFeatureVersion
{
    public const int PIPELINE_VERSION = 1;

    /**
     * @var array<string, array{version: int, migration: class-string<MetadataFeatureMigrationInterface>|null}>
     */
    private const array DEFAULT_NAMESPACES = [
        'calendar' => ['version' => 1, 'migration' => null],
        'solar'    => ['version' => 1, 'migration' => null],
        'file'     => ['version' => 1, 'migration' => null],
        'legacy'   => ['version' => 1, 'migration' => null],
    ];

    /**
     * @var array<string, array{version: int, migration: class-string<MetadataFeatureMigrationInterface>|null}>
     */
    private static array $namespaces = self::DEFAULT_NAMESPACES;

    public const int CURRENT = self::PIPELINE_VERSION;

    /**
     * Returns the configured feature namespace definitions including migrations.
     *
     * @return array<string, array{version: int, migration: class-string<MetadataFeatureMigrationInterface>|null}>
     */
    public static function namespaces(): array
    {
        return self::$namespaces;
    }

    /**
     * Returns the version for a particular feature namespace.
     */
    public static function namespaceVersion(string $namespace): int
    {
        $definition = self::$namespaces[$namespace] ?? null;

        return $definition['version'] ?? self::PIPELINE_VERSION;
    }

    /**
     * Returns the migration class responsible for the namespace, if any.
     */
    public static function namespaceMigration(string $namespace): ?string
    {
        $definition = self::$namespaces[$namespace] ?? null;

        return $definition['migration'] ?? null;
    }

    /**
     * Registers or overrides the version for a namespace including the migration handler.
     *
     * @param class-string<MetadataFeatureMigrationInterface>|null $migration
     */
    public static function registerNamespace(string $namespace, int $version, ?string $migration = null): void
    {
        if ($namespace === '') {
            throw new InvalidArgumentException('Namespace must not be empty.');
        }

        if ($version < 1) {
            throw new InvalidArgumentException('Version must be a positive integer.');
        }

        if ($migration !== null && !is_subclass_of($migration, MetadataFeatureMigrationInterface::class)) {
            throw new InvalidArgumentException(sprintf('Migration %s must implement %s.', $migration, MetadataFeatureMigrationInterface::class));
        }

        self::$namespaces[$namespace] = [
            'version'   => $version,
            'migration' => $migration,
        ];
    }

    /**
     * Restores the default namespace configuration.
     */
    public static function reset(): void
    {
        self::$namespaces = self::DEFAULT_NAMESPACES;
    }

    private function __construct()
    {
    }
}
