<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

interface WeatherObservationStorageInterface
{
    public function findHint(float $lat, float $lon, int $timestamp): ?array;

    public function hasObservation(float $lat, float $lon, int $timestamp): bool;

    /**
     * @param array<string, mixed> $hint
     */
    public function storeHint(float $lat, float $lon, int $timestamp, array $hint, string $source): void;
}
