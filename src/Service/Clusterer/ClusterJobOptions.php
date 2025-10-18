<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use DateTimeImmutable;

/**
 * Class ClusterJobOptions.
 */
final readonly class ClusterJobOptions
{
    /**
     * @param list<string>|null $allowedGroups
     */
    public function __construct(
        private bool $dryRun,
        private ?int $limit,
        private ?DateTimeImmutable $since,
        private bool $replace,
        private bool $vacationDebug = false,
        private ?DateTimeImmutable $until = null,
        private ?array $allowedGroups = null,
    ) {
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getSince(): ?DateTimeImmutable
    {
        return $this->since;
    }

    public function shouldReplace(): bool
    {
        return $this->replace;
    }

    public function isVacationDebugEnabled(): bool
    {
        return $this->vacationDebug;
    }

    public function getUntil(): ?DateTimeImmutable
    {
        return $this->until;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedGroups(): ?array
    {
        return $this->allowedGroups;
    }
}
