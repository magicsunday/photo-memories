<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Rules;

use DateTimeImmutable;

class PresetRule
{
    public string $name;
    public int $priority;
    public ?int $minDays;
    public ?int $maxDays;
    public ?string $season;
    public ?string $placeHint;
    public string $template;

    public function __construct(
        string $name,
        int $priority,
        ?int $minDays,
        ?int $maxDays,
        ?string $season,
        ?string $placeHint,
        string $template
    ) {
        $this->name = $name;
        $this->priority = $priority;
        $this->minDays = $minDays;
        $this->maxDays = $maxDays;
        $this->season = $season;
        $this->placeHint = $placeHint;
        $this->template = $template;
    }

    /**
     * Check if this rule matches the given cluster data.
     */
    public function matches(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?string $placeName,
        string $season
    ): bool {
        $days = $start->diff($end)->days + 1;

        if ($this->minDays !== null && $days < $this->minDays) {
            return false;
        }

        if ($this->maxDays !== null && $days > $this->maxDays) {
            return false;
        }

        if ($this->season !== null && $this->season !== $season) {
            return false;
        }

        if ($this->placeHint !== null && $placeName !== null) {
            if (stripos($placeName, $this->placeHint) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the rule template into a title string.
     */
    public function render(string $season, ?string $placeName, ?string $category): string
    {
        $title = $this->template;
        $title = str_replace('{season}', $season, $title);
        $title = str_replace('{place}', $placeName ?? '', $title);
        $title = str_replace('{category}', $category ?? 'Travel', $title);
        return trim($title);
    }
}
