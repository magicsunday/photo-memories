<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories;

use MagicSunday\Memories\Bootstrap\EnvironmentBootstrap;
use Phar;
use RuntimeException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Console\Style\SymfonyStyle;

use function class_exists;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function mkdir;
use function sprintf;

/**
 * Factory responsible for compiling and loading the dependency container.
 */
final class DependencyContainerFactory
{
    private const CONTAINER_CLASS = 'DependencyContainer';

    private const CONTAINER_NAMESPACE = __NAMESPACE__;

    private string $cacheDirectory;

    private string $cacheFile;

    public function __construct()
    {
        $this->cacheDirectory = __DIR__ . '/../var/cache';
        $this->cacheFile      = $this->cacheDirectory . '/' . self::CONTAINER_CLASS . '.php';
    }

    /**
     * Ensures that the cached container exists on disk.
     */
    public function ensure(): void
    {
        EnvironmentBootstrap::boot();

        $this->createCacheDirectoryIfMissing();

        if (is_file($this->cacheFile)) {
            return;
        }

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', $this->resolveProjectDir());
        $containerBuilder->setParameter('kernel.environment', getenv('APP_ENV') ?: 'prod');
        $containerBuilder->setParameter('kernel.debug', (bool) (getenv('APP_DEBUG') ?: false));

        $yamlFileLoader = new YamlFileLoader($containerBuilder, new FileLocator(__DIR__ . '/../config'));
        $yamlFileLoader->load('services.yaml');

        $containerBuilder
            ->register(SymfonyStyle::class)
            ->setPublic(true)
            ->setSynthetic(true);

        $containerBuilder->compile();

        $dumper = new PhpDumper($containerBuilder);

        file_put_contents(
            $this->cacheFile,
            $dumper->dump(
                [
                    'class'     => self::CONTAINER_CLASS,
                    'namespace' => self::CONTAINER_NAMESPACE,
                ]
            )
        );
    }

    /**
     * Creates and returns the dependency container instance.
     */
    public function create(): DependencyContainer
    {
        $this->ensure();

        require_once $this->cacheFile;

        return new DependencyContainer();
    }

    private function createCacheDirectoryIfMissing(): void
    {
        if (is_dir($this->cacheDirectory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->cacheDirectory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $this->cacheDirectory
                )
            );
        }
    }

    private function resolveProjectDir(): string
    {
        if (class_exists(Phar::class) && Phar::running() !== '') {
            return dirname(Phar::running(false));
        }

        return dirname(__DIR__);
    }
}
