<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Hash\Contract;

/**
 * Describes a service capable of computing fast file hashes.
 */
interface FastHashGeneratorInterface
{
    /**
     * Computes a fast hash for the provided file path.
     *
     * @param string $filePath absolute path to the file that should be hashed
     *
     * @return string|null the computed hash value or null if hashing failed
     */
    public function hash(string $filePath): ?string;
}
