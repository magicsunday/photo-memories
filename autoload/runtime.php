<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Bootstrap;

use RuntimeException;

use function dirname;
use function is_file;

/**
 * Ensures that the Composer autoloader is registered.
 */
function requireComposerAutoload(): void
{
    static $autoloadLoaded = false;

    if ($autoloadLoaded === true) {
        return;
    }

    $autoloadPaths = [
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 3) . '/autoload.php',
    ];

    foreach ($autoloadPaths as $autoloadPath) {
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
            $autoloadLoaded = true;

            return;
        }
    }

    throw new RuntimeException('Composer autoload file not found.');
}
