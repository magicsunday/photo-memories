<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\DependencyInjection\Compiler;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use function array_map;
use function array_unique;
use function array_values;
use function file;
use function count;
use function implode;
use function is_array;
use function is_string;
use function ltrim;
use function preg_split;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function sprintf;
use function strrpos;
use function substr;
use function trim;
use function rtrim;

/**
 * Logs a warning when parameters are declared multiple times across imported YAML files.
 */
final class DuplicateParameterGuardCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $parameterFiles = $this->collectParameterFiles($container);

        if ($parameterFiles === []) {
            return;
        }

        $projectDir = $this->resolveProjectDir($container);

        /** @var array<string, list<string>> $parameterDeclarations */
        $parameterDeclarations = [];

        foreach ($parameterFiles as $parameterFile) {
            $analysis = $this->analyseParameterFile($parameterFile);

            foreach ($analysis['duplicates'] as $parameterName => $lines) {
                $relativePath = $this->makeRelativePath($projectDir, $parameterFile);

                sort($lines);

                $container->log(
                    $this,
                    sprintf(
                        'Parameter "%s" is defined multiple times in "%s" (lines %s). The last occurrence wins; consolidate the definition.',
                        $parameterName,
                        $relativePath,
                        implode(', ', $lines),
                    ),
                );
            }

            foreach ($analysis['parameters'] as $parameterName) {
                $parameterDeclarations[$parameterName][] = $parameterFile;
            }
        }

        foreach ($parameterDeclarations as $parameterName => $files) {
            if (count($files) < 2) {
                continue;
            }

            $relativeFiles = array_map(
                fn (string $file): string => $this->makeRelativePath($projectDir, $file),
                $this->uniqueFiles($files),
            );

            $container->log(
                $this,
                sprintf(
                    'Parameter "%s" is defined in multiple files: %s. Import order determines the winning value.',
                    $parameterName,
                    implode(', ', $relativeFiles),
                ),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function collectParameterFiles(ContainerBuilder $container): array
    {
        $parameterFiles = [];

        foreach ($container->getResources() as $resource) {
            if (!$resource instanceof FileResource) {
                continue;
            }

            $path = $resource->getResource();
            $normalisedPath = str_replace('\\', '/', $path);

            if (!str_ends_with($normalisedPath, '.yaml') && !str_ends_with($normalisedPath, '.yml')) {
                continue;
            }

            if (!str_contains($normalisedPath, '/config/parameters') && !str_ends_with($normalisedPath, '/config/parameters.yaml')) {
                continue;
            }

            $parameterFiles[] = $path;
        }

        return array_values(array_unique($parameterFiles));
    }

    /**
     * @return array{parameters: list<string>, duplicates: array<string, list<int>>}
     */
    private function analyseParameterFile(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [
                'parameters' => [],
                'duplicates' => [],
            ];
        }

        $inParametersSection = false;
        $parametersIndentation = 0;

        /** @var array<string, int> $firstSeen */
        $firstSeen = [];

        /** @var list<string> $parameterNames */
        $parameterNames = [];

        /** @var array<string, list<int>> $duplicateLines */
        $duplicateLines = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $indentation = strlen($line) - strlen($trimmed);

            if (!$inParametersSection) {
                if (str_starts_with($trimmed, 'parameters:')) {
                    $inParametersSection = true;
                    $parametersIndentation = $indentation;
                }

                continue;
            }

            if ($indentation <= $parametersIndentation) {
                break;
            }

            if ($indentation !== $parametersIndentation + 4) {
                continue;
            }

            $lineWithoutComment = $this->stripInlineComment($trimmed);

            $separatorPosition = strrpos($lineWithoutComment, ':');

            if ($separatorPosition === false) {
                continue;
            }

            $rawKey = rtrim(substr($lineWithoutComment, 0, $separatorPosition));

            if ($rawKey === '') {
                continue;
            }

            $parameterName = $this->normalizeParameterKey($rawKey);

            if ($parameterName === '') {
                continue;
            }

            if (!isset($firstSeen[$parameterName])) {
                $firstSeen[$parameterName] = $lineNumber;
                $parameterNames[] = $parameterName;

                continue;
            }

            if (!isset($duplicateLines[$parameterName])) {
                $duplicateLines[$parameterName] = [$firstSeen[$parameterName]];
            }

            $duplicateLines[$parameterName][] = $lineNumber;
        }

        foreach ($duplicateLines as $name => $linesForKey) {
            $normalizedLines = array_map(
                static fn (int|string $line): int => (int) $line,
                array_unique($linesForKey),
            );

            sort($normalizedLines);

            $duplicateLines[$name] = $normalizedLines;
        }

        return [
            'parameters' => array_values(array_unique($parameterNames)),
            'duplicates' => $duplicateLines,
        ];
    }

    private function stripInlineComment(string $line): string
    {
        $parts = preg_split('/\s+#/', $line, 2);

        if (!is_array($parts) || $parts === []) {
            return $line;
        }

        return trim($parts[0]);
    }

    private function normalizeParameterKey(string $rawKey): string
    {
        $normalized = trim($rawKey);

        $length = strlen($normalized);

        if ($length >= 2) {
            $firstCharacter = $normalized[0];
            $lastCharacter = $normalized[$length - 1];

            if (($firstCharacter === '\'' && $lastCharacter === '\'') || ($firstCharacter === '"' && $lastCharacter === '"')) {
                return substr($normalized, 1, -1);
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function uniqueFiles(array $files): array
    {
        $unique = [];
        $seen = [];

        foreach ($files as $file) {
            if (isset($seen[$file])) {
                continue;
            }

            $seen[$file] = true;
            $unique[] = $file;
        }

        return $unique;
    }

    private function resolveProjectDir(ContainerBuilder $container): ?string
    {
        if ($container->hasParameter('kernel.project_dir')) {
            $projectDir = $container->getParameter('kernel.project_dir');

            if (is_string($projectDir) && $projectDir !== '') {
                return $projectDir;
            }
        }

        return null;
    }

    private function makeRelativePath(?string $projectDir, string $path): string
    {
        $normalisedPath = str_replace('\\', '/', $path);

        if ($projectDir === null) {
            return $normalisedPath;
        }

        $normalisedProjectDir = rtrim(str_replace('\\', '/', $projectDir), '/');

        if ($normalisedProjectDir === '') {
            return $normalisedPath;
        }

        $prefix = $normalisedProjectDir.'/';

        if (str_starts_with($normalisedPath, $prefix)) {
            return substr($normalisedPath, strlen($prefix));
        }

        return $normalisedPath;
    }
}
