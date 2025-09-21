<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

/**
 * Lightweight image adapter abstraction for pixel access and resizing.
 */
interface ImageAdapterInterface
{
    public function getWidth(): int;

    public function getHeight(): int;

    /**
     * Luma in the range [0..255].
     */
    public function getLuma(int $x, int $y): float;

    /**
     * Return a resized copy (does not mutate the original).
     */
    public function resize(int $targetWidth, int $targetHeight): ImageAdapterInterface;

    /**
     * Free underlying resources.
     */
    public function destroy(): void;
}
