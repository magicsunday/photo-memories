<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\DependencyInjection\Compiler;

use MagicSunday\Memories\DependencyInjection\Compiler\DuplicateParameterGuardCompilerPass;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(DuplicateParameterGuardCompilerPass::class)]
final class DuplicateParameterGuardCompilerPassTest extends TestCase
{
    public function testItLogsDuplicateParameterKeysWithinSingleFile(): void
    {
        $fixtureDir = realpath(__DIR__ . '/../../../Support/Fixtures/DependencyInjection/DuplicateParameters/single');
        self::assertNotFalse($fixtureDir);
        $fixtureDir = (string) $fixtureDir;
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $fixtureDir);
        $container->addCompilerPass(new DuplicateParameterGuardCompilerPass());
        $container->addResource(new FileResource($fixtureDir . '/config/parameters.yaml'));

        $container->compile();

        $expectedMessage = DuplicateParameterGuardCompilerPass::class . ': Parameter "memories.test.duplicate" is defined multiple times in "config/parameters.yaml" (lines 2, 3). The last occurrence wins; consolidate the definition.';
        $logMessages     = $container->getCompiler()->getLog();

        self::assertContains($expectedMessage, $logMessages, print_r($logMessages, true));
    }

    public function testItLogsDuplicateParametersAcrossMultipleFiles(): void
    {
        $fixtureDir = realpath(__DIR__ . '/../../../Support/Fixtures/DependencyInjection/DuplicateParameters/cross-file');
        self::assertNotFalse($fixtureDir);
        $fixtureDir = (string) $fixtureDir;
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $fixtureDir);
        $container->addCompilerPass(new DuplicateParameterGuardCompilerPass());
        $container->addResource(new FileResource($fixtureDir . '/config/parameters.yaml'));
        $container->addResource(new FileResource($fixtureDir . '/config/parameters/override.yaml'));

        $container->compile();

        $expectedMessage = DuplicateParameterGuardCompilerPass::class . ': Parameter "memories.test.cross.shared" is defined in multiple files: config/parameters.yaml, config/parameters/override.yaml. Import order determines the winning value.';
        $logMessages     = $container->getCompiler()->getLog();

        self::assertContains($expectedMessage, $logMessages, print_r($logMessages, true));
    }
}
