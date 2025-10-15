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
 * Represents the time span covered by a cluster.
 */
final readonly class ClusterSummaryTimeRange
{
    public function __construct(
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
    ) {
    }

    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): DateTimeImmutable
    {
        return $this->to;
    }
}
