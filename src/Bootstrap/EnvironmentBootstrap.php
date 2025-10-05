<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Bootstrap;

use Phar;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;

use function dirname;

/**
 * Selects and loads the applicable .env file from the available application roots.
 *
 * The loader considers the current working directory, the directory of a running
 * PHAR archive, and the project root to determine the first readable candidate.
 * Side effects: delegates to Dotenv::bootEnv() for cascading .env variants and
 * utilises Phar::running() to discover packaged execution contexts.
 */
final class EnvironmentBootstrap
{
    /**
     * Bootstraps environment variables from a single .env root.
     *
     * Precedence for the root (first hit wins):
     *  1) MEMORIES_ENV_DIR/.env (optional override)
     *  2) CWD/.env
     *  3) <PHAR DIR>/.env
     *  4) <REPO ROOT>/.env (../.env relativ zu diesem File)
     *
     * Uses Dotenv::bootEnv() to also process .env.local / .env.$APP_ENV(.local).
     */
    public static function boot(): void
    {
        $dotenv = new Dotenv();
        $dotenv->usePutenv();

        $candidates = [];

        // 1) Current working directory
        $cwd = getcwd();
        if (\is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . '.env';
        }

        // 2) Directory of running PHAR (if any)
        try {
            $pharPath = Phar::running(false); // '' if not in PHAR
            if ($pharPath !== '') {
                $candidates[] = dirname($pharPath) . DIRECTORY_SEPARATOR . '.env';
            }
        } catch (Throwable) {
            // ignore: not running in PHAR or phar not initialized
        }

        // 3) Repo root (dev)
        $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        $rootEnv      = array_find($candidates, fn ($file): bool => is_file($file));

        if ($rootEnv === null) {
            // No .env found is fine; rely on process env only
            return;
        }

        // bootEnv loads .env + .env.local + .env.$APP_ENV(.local) in correct order,
        // and respects existing process env (no override).
        $dotenv->bootEnv($rootEnv);
    }
}
