<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Generator;

use MagicSunday\Memories\Model\Memory;
use MagicSunday\Memories\Rules\PresetRule;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class PresetTitleGenerator
{
    /** @var PresetRule[] */
    private array $rules = [];

    private array $categories = [];

    public function __construct(string $configFile)
    {
        if (!file_exists($configFile)) {
            throw new RuntimeException('Preset config file not found: ' . $configFile);
        }

        $data = Yaml::parseFile($configFile);

        // Load categories
        $this->categories = $data['placeCategories'] ?? [];

        // Load preset rules
        foreach ($data['presets'] as $preset) {
            $this->rules[] = new PresetRule(
                $preset['name'],
                $preset['priority'] ?? 0,
                $preset['minDays'] ?? null,
                $preset['maxDays'] ?? null,
                $preset['season'] ?? null,
                $preset['placeHint'] ?? null,
                $preset['template']
            );
        }

        // Sort rules by priority (desc)
        usort($this->rules, fn ($a, $b): int => $b->priority <=> $a->priority);
    }

    /**
     * Detect semantic category (beach, mountains, city, …).
     */
    private function detectCategory(?string $placeName): ?string
    {
        if ($placeName === null || $placeName === '' || $placeName === '0') {
            return null;
        }

        foreach ($this->categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($placeName, (string) $keyword) !== false) {
                    return ucfirst((string) $category);
                }
            }
        }

        return null;
    }

    /**
     * Generate a smart title for a memory.
     */
    public function generate(Memory $memory): ?string
    {
        $start = $memory->start;
        $end   = $memory->end;

        // Use city if available, otherwise fall back to country
        $placeName = $memory->city ?? $memory->country ?? null;

        $month  = (int) $start->format('n');
        $season = match ($month) {
            12, 1, 2 => 'Winter',
            3, 4, 5 => 'Frühling',
            6, 7, 8 => 'Sommer',
            9, 10, 11 => 'Herbst',
        };

        $category = $this->detectCategory($placeName);

        foreach ($this->rules as $rule) {
            if ($rule->matches($start, $end, $placeName, $season)) {
                return $rule->render($season, $placeName, $category);
            }
        }

        return null;
    }
}
