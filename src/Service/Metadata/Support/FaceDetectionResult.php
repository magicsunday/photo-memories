<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use InvalidArgumentException;

/**
 * Value object describing the outcome of a face-detection run.
 */
final class FaceDetectionResult
{
    private bool $available;

    private int $facesCount;

    private function __construct(bool $available, int $facesCount)
    {
        if ($facesCount < 0) {
            throw new InvalidArgumentException('facesCount must be greater or equal to zero.');
        }

        $this->available   = $available;
        $this->facesCount  = $facesCount;
    }

    public static function unavailable(): self
    {
        return new self(false, 0);
    }

    public static function fromCount(int $facesCount): self
    {
        return new self(true, $facesCount);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function hasFaces(): bool
    {
        return $this->facesCount > 0;
    }

    public function getFacesCount(): int
    {
        return $this->facesCount;
    }
}
