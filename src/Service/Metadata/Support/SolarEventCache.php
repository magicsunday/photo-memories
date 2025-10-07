<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

/**
 * In-memory cache for solar event calculations.
 */
final class SolarEventCache
{
    /**
     * @var array<string, SolarEventResult>
     */
    private array $store = [];

    public function get(string $key): ?SolarEventResult
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, SolarEventResult $value): void
    {
        $this->store[$key] = $value;
    }
}
