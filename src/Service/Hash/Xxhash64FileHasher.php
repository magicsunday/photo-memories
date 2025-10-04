<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Hash;

use MagicSunday\Memories\Service\Hash\Contract\FastHashGeneratorInterface;

use function hash_file;

/**
 * Computes xxHash64 digests for files.
 */
final class Xxhash64FileHasher implements FastHashGeneratorInterface
{
    public function hash(string $filePath): ?string
    {
        $hash = @hash_file('xxh64', $filePath);

        if ($hash === false) {
            return null;
        }

        return $hash;
    }
}
