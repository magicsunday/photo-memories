<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Clusterer\Contract\PersonTaggedMediaInterface;
use MagicSunday\Memories\Entity\Media;

use function array_map;
use function array_unique;
use function array_values;
use function hash;
use function intval;
use function mb_strtolower;
use function substr;
use function trim;

/**
 * Generates stable numeric person signatures for media items.
 */
final class PersonSignatureHelper
{
    /**
     * @var array<string, int>
     */
    private array $cache = [];

    /**
     * Returns person identifiers for the given media, falling back to
     * a deterministic hash of person names when numeric identifiers are
     * not provided explicitly.
     *
     * @return list<int>
     */
    public function personIds(Media $media): array
    {
        if ($media instanceof PersonTaggedMediaInterface) {
            return $media->getPersonIds();
        }

        $persons = $media->getPersons();
        if ($persons === null) {
            return [];
        }

        $normalised = array_values(array_unique(array_map(
            static fn (string $name): string => mb_strtolower(trim($name)),
            $persons
        )));

        $ids = [];
        foreach ($normalised as $name) {
            if ($name === '') {
                continue;
            }

            $ids[] = $this->cache[$name] ??= $this->hashPerson($name);
        }

        return $ids;
    }

    private function hashPerson(string $name): int
    {
        $hash  = substr(hash('sha256', $name), 0, 15);
        $value = intval($hash, 16);

        if ($value < 1) {
            return 1;
        }

        return $value;
    }
}
