<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\DependencyInjection;

use MagicSunday\Memories\DependencyInjection\Compiler\DuplicateParameterGuardCompilerPass;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

#[CoversClass(DuplicateParameterGuardCompilerPass::class)]
final class DuplicateParameterGuardCompilerPassIntegrationTest extends TestCase
{
    public function testItLogsDuplicateParameterWarningsDuringCompilation(): void
    {
        $fixtureDir = realpath(__DIR__ . '/../../Support/Fixtures/DependencyInjection/DuplicateParameters/integration');
        self::assertNotFalse($fixtureDir);
        $fixtureDir = (string) $fixtureDir;

        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $fixtureDir);
        $container->addCompilerPass(new DuplicateParameterGuardCompilerPass());

        $loader = new YamlFileLoader($container, new FileLocator($fixtureDir . '/config'));
        $loader->load('services.yaml');

        $container->addResource(new FileResource($fixtureDir . '/config/parameters.yaml'));

        $container->compile();

        $expectedMessage = DuplicateParameterGuardCompilerPass::class . ': Parameter "memories.test.integration" is defined multiple times in "config/parameters.yaml" (lines 2, 3). The last occurrence wins; consolidate the definition.';

        self::assertContains($expectedMessage, $container->getCompiler()->getLog());
    }
}
