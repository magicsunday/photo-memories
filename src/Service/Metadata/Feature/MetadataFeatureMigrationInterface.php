<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Feature;

/**
 * Contract for feature namespace migrations used by {@see MetadataFeatureVersion}.
 */
interface MetadataFeatureMigrationInterface
{
    /**
     * Applies the migration to the provided feature payload.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function migrate(array $payload): array;
}
