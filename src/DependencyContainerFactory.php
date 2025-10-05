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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

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
    private const string CONTAINER_CLASS = 'DependencyContainer';

    private const string CONTAINER_NAMESPACE = __NAMESPACE__;

    /**
     * Absolute directory path where the compiled container cache is stored.
     *
     * This always resolves to "var/cache" within the project root, even when
     * running from a packaged PHAR archive.
     */
    private string $cacheDirectory;

    /**
     * Fully-qualified file path to the cached container PHP class.
     *
     * The file lives inside {@see $cacheDirectory} and follows the
     * "DependencyContainer.php" naming convention so that the class can be
     * required directly.
     */
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

        // Register SymfonyStyle so that console commands can inject it; the actual
        // instance is provided by the command runtime and therefore marked synthetic.
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
     *
     * @return ContainerInterface
     */
    public function create(): ContainerInterface
    {
        $this->ensure();

        require_once $this->cacheFile;

        /** @var class-string $className */
        $className = sprintf('%s\\%s', self::CONTAINER_NAMESPACE, self::CONTAINER_CLASS);

        if (!class_exists($className)) {
            throw new RuntimeException(sprintf('Cached container class "%s" was not generated.', $className));
        }

        $container = new $className();

        if (!$container instanceof ContainerInterface) {
            throw new RuntimeException(
                sprintf(
                    'Cached container class "%s" must implement %s.',
                    $className,
                    ContainerInterface::class,
                ),
            );
        }

        return $container;
    }

    /**
     * Ensures the cache directory exists before writing the compiled container.
     *
     * Creates "var/cache" recursively and raises an exception when another
     * process fails to create it correctly.
     */
    private function createCacheDirectoryIfMissing(): void
    {
        if (is_dir($this->cacheDirectory)) {
            return;
        }

        // The double check accounts for concurrent mkdir calls. Only fail when
        // the directory still does not exist after attempting to create it.
        if (!mkdir($concurrentDirectory = $this->cacheDirectory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $this->cacheDirectory
                )
            );
        }
    }

    /**
     * Determines the project root path for dependency container configuration.
     *
     * Returns the directory of the running PHAR when packaged, otherwise the
     * repository root relative to this source file.
     */
    private function resolveProjectDir(): string
    {
        // When bundled as a PHAR, resolve the path to the archive instead of the
        // filesystem location of the source files.
        if (class_exists(Phar::class) && Phar::running() !== '') {
            return dirname(Phar::running(false));
        }

        return dirname(__DIR__);
    }
}
