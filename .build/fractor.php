<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use a9f\Fractor\Configuration\FractorConfiguration;

return FractorConfiguration::configure()
    ->withPaths(
        [
            __DIR__ . '/../src/',
            __DIR__ . '/../test/',
        ]
    );
