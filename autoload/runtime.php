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
final class ComposerAutoload
{
    private static bool $autoloadLoaded = false;

    /**
     * Registers the Composer autoloader once per process.
     */
    public static function require(): void
    {
        if (self::$autoloadLoaded === true) {
            return;
        }

        $autoloadPaths = [
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 3) . '/autoload.php',
        ];

        foreach ($autoloadPaths as $autoloadPath) {
            if (is_file($autoloadPath)) {
                require_once $autoloadPath;
                self::$autoloadLoaded = true;

                return;
            }
        }

        throw new RuntimeException('Composer autoload file not found.');
    }
}
