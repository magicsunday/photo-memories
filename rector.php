<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\Closure\RemoveUnusedClosureVariableUseRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src/',
        __DIR__ . '/tests/',
    ]);

    // Keep the Rector caches inside the build directory rather than the repository root.
    $rectorCacheDirectory          = __DIR__ . '/.build/cache/.rector.cache';
    $rectorContainerCacheDirectory = __DIR__ . '/.build/cache/.rector.container.cache';

    foreach ([$rectorCacheDirectory, $rectorContainerCacheDirectory] as $cacheDirectory) {
        if (
            !is_dir($cacheDirectory)
            && !mkdir($cacheDirectory, 0o775, true)
            && !is_dir($cacheDirectory)
        ) {
            throw new RuntimeException(sprintf('Directory "%s" was not created.', $cacheDirectory));
        }
    }

    $rectorConfig->cacheDirectory($rectorCacheDirectory);
    $rectorConfig->containerCacheDirectory($rectorContainerCacheDirectory);
    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');

    // The shared rule sets and skips; 80400 is this application's PHP floor.
    (require __DIR__ . '/vendor/magicsunday/coding-standard/rector/base.php')($rectorConfig, 80400);

    // Repository-specific skips on top of the shared ones.
    $rectorConfig->skip([
        ClassPropertyAssignToConstructorPromotionRector::class,
        NewMethodCallWithoutParenthesesRector::class,
        RemoveUselessVarTagRector::class,
        RemoveUnusedPrivateMethodParameterRector::class,

        // RunDetector::collectRuns() flushes completed runs through a closure that mutates
        // $run/$runs by reference. Rector's flow analysis does not model the by-reference
        // mutation performed via the $flush() calls in the following loop, so it wrongly
        // treats the "if ($run === [])" guard as always-true and strips the closure body
        // (and its now-"unused" use() captures), producing an empty no-op flush. Skip the
        // triggering dead-code rules for this file to preserve the by-reference behaviour.
        RemoveAlwaysTrueIfConditionRector::class => [
            __DIR__ . '/src/Clusterer/Service/RunDetector.php',
        ],
        RemoveUnusedClosureVariableUseRector::class => [
            __DIR__ . '/src/Clusterer/Service/RunDetector.php',
        ],
    ]);
};
