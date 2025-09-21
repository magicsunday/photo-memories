<?php
declare(strict_types=1);

namespace MagicSunday\Memories;

use Phar;
use Throwable;
use Symfony\Component\Dotenv\Dotenv;
use function dirname;

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
        if (is_string($cwd) && $cwd !== '') {
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

        // Pick first existing .env
        $rootEnv = null;
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $rootEnv = $file;
                break;
            }
        }

        if ($rootEnv === null) {
            // No .env found is fine; rely on process env only
            return;
        }

        // bootEnv loads .env + .env.local + .env.$APP_ENV(.local) in correct order,
        // and respects existing process env (no override).
        $dotenv->bootEnv($rootEnv);
    }
}
