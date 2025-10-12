<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Contract;

/**
 * Interface ProgressHandleInterface.
 */
interface ProgressHandleInterface
{
    public function advance(int $step = 1): void;

    public function setPhase(?string $message): void;

    public function setDetail(?string $message): void;

    public function setRate(?string $message): void;

    public function setProgress(int $current): void;

    public function finish(): void;
}
