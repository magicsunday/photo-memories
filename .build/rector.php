<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Closure\RemoveUnusedClosureVariableUseRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/../src/',
        __DIR__ . '/../test/',
    ]);

    if (
        !is_dir($concurrentDirectory = __DIR__ . '/cache/.rector.cache')
        && !mkdir($concurrentDirectory, 0775, true)
        && !is_dir($concurrentDirectory)
    ) {
        throw new RuntimeException(
            sprintf(
                'Directory "%s" was not created',
                $concurrentDirectory
            )
        );
    }

    if (
        !is_dir($concurrentDirectory = __DIR__ . '/cache/.rector.container.cache')
        && !mkdir($concurrentDirectory, 0775, true)
        && !is_dir($concurrentDirectory)
    ) {
        throw new RuntimeException(
            sprintf(
                'Directory "%s" was not created',
                $concurrentDirectory
            )
        );
    }

    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');
    $rectorConfig->importNames();
    $rectorConfig->removeUnusedImports();
    $rectorConfig->disableParallel();
    $rectorConfig->cacheDirectory(__DIR__ . '/cache/.rector.cache');
    $rectorConfig->containerCacheDirectory(__DIR__ . '/cache/.rector.container.cache');

    // Define what rule sets will be applied
    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
        SetList::PRIVATIZATION,
        SetList::STRICT_BOOLEANS,
        SetList::TYPE_DECLARATION,
        LevelSetList::UP_TO_PHP_84,
    ]);

    // Skip some rules
    $rectorConfig->skip([
        CatchExceptionNameMatchingTypeRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        NewMethodCallWithoutParenthesesRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
        RemoveUselessVarTagRector::class,
        RemoveUnusedPrivateMethodParameterRector::class,

        // RunDetector::collectRuns() flushes completed runs through a closure that mutates
        // $run/$runs by reference. Rector's flow analysis does not model the by-reference
        // mutation performed via the $flush() calls in the following loop, so it wrongly
        // treats the "if ($run === [])" guard as always-true and strips the closure body
        // (and its now-"unused" use() captures), producing an empty no-op flush. Skip the
        // triggering dead-code rules for this file to preserve the by-reference behaviour.
        RemoveAlwaysTrueIfConditionRector::class => [
            __DIR__ . '/../src/Clusterer/Service/RunDetector.php',
        ],
        RemoveUnusedClosureVariableUseRector::class => [
            __DIR__ . '/../src/Clusterer/Service/RunDetector.php',
        ],
    ]);
};
