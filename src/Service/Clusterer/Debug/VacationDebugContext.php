<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Debug;

/**
 * Collects debug information for vacation clustering runs.
 */
final class VacationDebugContext
{
    private bool $enabled = false;

    /**
     * @var list<array{
     *     start_date: string,
     *     end_date: string,
     *     away_days: int,
     *     members: int,
     *     center_count: int,
     *     radius_km: float,
     *     density: float,
     * }>
     */
    private array $segments = [];

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->segments = [];
        $this->warnings = [];
    }

    public function reset(): void
    {
        $this->segments = [];
        $this->warnings = [];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array{
     *     start_date: string,
     *     end_date: string,
     *     away_days: int,
     *     members: int,
     *     center_count: int,
     *     radius_km: float,
     *     density: float,
     * } $segment
     */
    public function recordSegment(array $segment): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->segments[] = $segment;
    }

    /**
     * @return list<array{
     *     start_date: string,
     *     end_date: string,
     *     away_days: int,
     *     members: int,
     *     center_count: int,
     *     radius_km: float,
     *     density: float,
     * }>
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    public function recordWarning(string $message): void
    {
        if ($message === '') {
            return;
        }

        if (!\in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
