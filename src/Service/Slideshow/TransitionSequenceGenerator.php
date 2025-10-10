<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function hash;
use function implode;
use function is_int;
use function is_string;
use function trim;

/**
 * Generates deterministic transition sequences for slideshows.
 */
final class TransitionSequenceGenerator
{
    /**
     * @param list<string> $transitions
     * @param list<int>    $mediaIds
     *
     * @return list<string>
     */
    public static function generate(array $transitions, array $mediaIds, int $slideCount): array
    {
        if ($slideCount === 0 || $transitions === []) {
            return [];
        }

        $pool = [];
        foreach ($transitions as $transition) {
            if (!is_string($transition)) {
                continue;
            }

            $trimmed = trim($transition);
            if ($trimmed === '') {
                continue;
            }

            $pool[] = $trimmed;
        }

        if ($pool === []) {
            return [];
        }

        $seedSource = self::buildSeedSource($mediaIds, $slideCount);
        $randomizer = new Randomizer(new Xoshiro256StarStar($seedSource));

        $sequence = [];
        while (count($sequence) < $slideCount) {
            $shuffled = $randomizer->shuffleArray($pool);
            foreach ($shuffled as $transition) {
                $sequence[] = $transition;
                if (count($sequence) === $slideCount) {
                    break 2;
                }
            }
        }

        return $sequence;
    }

    /**
     * @param list<int> $mediaIds
     */
    private static function buildSeedSource(array $mediaIds, int $slideCount): string
    {
        $filteredIds = array_values(array_filter(
            $mediaIds,
            static fn (mixed $mediaId): bool => is_int($mediaId)
        ));

        $normalisedIds = array_map(
            static fn (int $mediaId): string => (string) $mediaId,
            $filteredIds
        );

        $payload = implode('|', $normalisedIds) . '|' . $slideCount;

        return hash('sha256', $payload, true);
    }
}
