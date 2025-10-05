<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Title;

use Phar;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

use function implode;
use function is_array;
use function is_file;
use function is_string;

/**
 * Loads i18n-able title templates per algorithm from YAML.
 * YAML structure:
 *  de:
 *    time_similarity:
 *      title: "Schnappschüsse"
 *      subtitle: "{{ date_range }}"
 *    vacation:
 *      title: "Reise nach {{ place }}"
 *      subtitle: "{{ start_date }} – {{ end_date }}".
 */
final class TitleTemplateProvider
{
    /** @var array<string, array<string, array{title:string,subtitle?:string}>> */
    private array $templates = [];

    public function __construct(
        private readonly string $configPath,
        private readonly string $locale = 'de',
    ) {
        $this->load();
    }

    private function load(): void
    {
        $resolvedPath  = $this->configPath;
        $searchedPaths = [$this->configPath];

        if (!is_file($resolvedPath)) {
            $pharPath = Phar::running(false);

            if ($pharPath !== '') {
                $fallbackPath    = 'phar://' . $pharPath . '/config/templates/titles.yaml';
                $searchedPaths[] = $fallbackPath;

                if (is_file($fallbackPath)) {
                    $resolvedPath = $fallbackPath;
                }
            }
        }

        if (!is_file($resolvedPath)) {
            throw new RuntimeException('Title-templates YAML missing: ' . implode(', ', $searchedPaths));
        }

        /** @var array<string,mixed> $data */
        $data = Yaml::parseFile($resolvedPath) ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        // Normalize to [locale][algorithm] = ['title'=>..., 'subtitle'=>...]
        foreach ($data as $loc => $algos) {
            if (!is_array($algos)) {
                continue;
            }

            foreach ($algos as $algo => $tpl) {
                if (is_array($tpl) && isset($tpl['title']) && is_string($tpl['title'])) {
                    $this->templates[$loc][$algo] = [
                        'title'    => $tpl['title'],
                        'subtitle' => isset($tpl['subtitle']) && is_string($tpl['subtitle']) ? $tpl['subtitle'] : '',
                    ];
                }
            }
        }
    }

    /** @return array{title:string,subtitle:string}|null */
    public function find(string $algorithm, ?string $locale = null): ?array
    {
        $loc = $locale ?? $this->locale;
        $hit = $this->templates[$loc][$algorithm] ?? null;
        if ($hit !== null) {
            return ['title' => $hit['title'], 'subtitle' => $hit['subtitle'] ?? ''];
        }

        // Fallback: try default locale „de“
        $hit = $this->templates['de'][$algorithm] ?? null;

        return $hit !== null ? ['title' => $hit['title'], 'subtitle' => $hit['subtitle'] ?? ''] : null;
    }
}
