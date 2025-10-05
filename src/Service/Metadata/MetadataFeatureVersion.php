<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

/**
 * Central definition of the metadata feature schema version.
 */
final class MetadataFeatureVersion
{
    public const int PIPELINE_VERSION = 1;

    /**
     * @var array<string, int>
     */
    public const array MODULE_VERSIONS = [
        'core' => 1,
        'exif' => 1,
        'xmp' => 1,
        'vision' => 1,
    ];

    public const int CURRENT = self::PIPELINE_VERSION;

    private function __construct()
    {
    }
}
