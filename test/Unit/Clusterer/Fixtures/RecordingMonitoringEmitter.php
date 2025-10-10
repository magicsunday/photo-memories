<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Fixtures;

use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

/**
 * Simple monitoring emitter that records emitted events for assertions.
 */
final class RecordingMonitoringEmitter implements JobMonitoringEmitterInterface
{
    /**
     * @var list<array{job:string,status:string,context:array<string,mixed>}> recorded events
     */
    public array $events = [];

    /**
     * {@inheritDoc}
     */
    public function emit(string $job, string $status, array $context = []): void
    {
        $this->events[] = [
            'job'     => $job,
            'status'  => $status,
            'context' => $context,
        ];
    }
}
