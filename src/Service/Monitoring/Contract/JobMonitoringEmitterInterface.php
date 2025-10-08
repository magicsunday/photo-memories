<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Monitoring\Contract;

/**
 * Emits monitoring information for long running jobs.
 */
interface JobMonitoringEmitterInterface
{
    /**
     * Emits a monitoring event for the given job name.
     *
     * @param array<string, mixed> $context
     */
    public function emit(string $job, string $status, array $context = []): void;
}
