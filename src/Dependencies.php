<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories;

use Phar;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use function dirname;
use function sprintf;

require_once __DIR__ . '/../vendor/autoload.php';

$cachedContainer = __DIR__ . '/../var/cache/DependencyContainer.php';
$cacheDir        = __DIR__ . '/../var/cache';

// Load environment variables from .env early.
EnvironmentBootstrap::boot();

// Create a cache directory if it doesn't exist
if (!file_exists($cacheDir)
    && !mkdir($cacheDir, 0775, true)
    && !is_dir($cacheDir)
) {
    throw new RuntimeException(
        sprintf(
            'Directory "%s" was not created',
            $cacheDir
        )
    );
}

// Create a cached container if it doesn't exists
if (!file_exists($cachedContainer)) {
    $projectDir = dirname(__DIR__, 3);

    // PHAR vs. Source
    if (class_exists(Phar::class) && (Phar::running() !== '')) {
        // Pfad zum .phar – je nach Struktur ggf. dirname() anpassen
        $projectDir = dirname(Phar::running(false));
    }

    // Create and configure the container
    $containerBuilder = new ContainerBuilder();
    $containerBuilder->setParameter('kernel.project_dir', $projectDir);
    $containerBuilder->setParameter('kernel.environment', getenv('APP_ENV') ?: 'prod');
    $containerBuilder->setParameter('kernel.debug', (bool) (getenv('APP_DEBUG') ?: false));

    // Load services from YAML configuration
    $yamlFileLoader = new YamlFileLoader($containerBuilder, new FileLocator(__DIR__ . '/../config'));
    $yamlFileLoader->load('services.yaml');

    // Register SymfonyStyle as a service
    $containerBuilder
        ->register(SymfonyStyle::class)
        ->setPublic(true)
        ->setSynthetic(true);

    // Compile the container
    $containerBuilder->compile();

    // Dump the container to a PHP file for caching
    $dumper = new PhpDumper($containerBuilder);

    file_put_contents(
        $cachedContainer,
        $dumper->dump(
            [
                'class'     => 'DependencyContainer',
                'namespace' => 'MagicSunday\Memories',
            ]
        )
    );
}
