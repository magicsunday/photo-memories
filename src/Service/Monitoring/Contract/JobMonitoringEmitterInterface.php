<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Monitoring\Contract;

use Stringable;

/**
 * Emits monitoring information for long running jobs.
 */
interface JobMonitoringEmitterInterface
{
    /**
     * Emits a monitoring event for the given job name.
     *
     * @param Stringable|string|int|float|bool $job
     * @param Stringable|string|int|float|bool $status
     * @param array<string, mixed>             $context
     */
    public function emit(Stringable|string|int|float|bool $job, Stringable|string|int|float|bool $status, array $context = []): void;
}
