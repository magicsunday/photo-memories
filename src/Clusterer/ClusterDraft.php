<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

/**
 * Minimal DTO used across strategies and persistence.
 */
final class ClusterDraft
{
    /**
     * @param array<string, scalar|array|null> $params
     * @param array{lat: float, lon: float}    $centroid
     * @param list<int>                        $members
     */
    public function __construct(
        private readonly string $algorithm,
        private array $params,
        private readonly array $centroid,
        private readonly array $members,
    ) {
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return array<string, scalar|array|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param scalar|array|null $value
     */
    public function setParam(string $key, $value): void
    {
        $this->params[$key] = $value;
    }

    /**
     * @return array{lat: float, lon: float}
     */
    public function getCentroid(): array
    {
        return $this->centroid;
    }

    /**
     * @return list<int>
     */
    public function getMembers(): array
    {
        return $this->members;
    }
}
