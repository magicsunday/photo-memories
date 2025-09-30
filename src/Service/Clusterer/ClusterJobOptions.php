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

final class ClusterJobOptions
{
    public function __construct(
        private readonly bool $dryRun,
        private readonly ?int $limit,
        private readonly ?DateTimeImmutable $since,
        private readonly bool $replace,
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
}
