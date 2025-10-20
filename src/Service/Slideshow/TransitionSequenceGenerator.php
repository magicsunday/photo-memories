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
use function array_unique;
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
     * @param list<string> $imagePaths
     *
     * @return list<string>
     */
    public static function generate(
        array $transitions,
        array $mediaIds,
        array $imagePaths,
        int $slideCount,
        ?string $title,
        ?string $subtitle,
        ?string $seedOverride = null,
    ): array
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

        $seedSource = self::buildSeedSource($mediaIds, $imagePaths, $slideCount, $title, $subtitle, $seedOverride);
        $randomizer = new Randomizer(new Xoshiro256StarStar($seedSource));

        $uniqueTransitions = array_values(array_unique($pool));
        $hasMultipleUniqueTransitions = count($uniqueTransitions) > 1;

        $sequence = [];
        $previousTransition = null;
        while (count($sequence) < $slideCount) {
            $shuffled = $randomizer->shuffleArray($pool);
            if ($hasMultipleUniqueTransitions && $previousTransition !== null && $shuffled !== []) {
                if ($shuffled[0] === $previousTransition) {
                    $swapIndex = null;
                    $shuffledCount = count($shuffled);
                    for ($index = 1; $index < $shuffledCount; $index++) {
                        if ($shuffled[$index] !== $previousTransition) {
                            $swapIndex = $index;
                            break;
                        }
                    }

                    if ($swapIndex !== null) {
                        $current = $shuffled[$swapIndex];
                        $shuffled[$swapIndex] = $shuffled[0];
                        $shuffled[0] = $current;
                    }
                }
            }

            foreach ($shuffled as $transition) {
                $sequence[] = $transition;
                $previousTransition = $transition;
                if (count($sequence) === $slideCount) {
                    break 2;
                }
            }
        }

        return $sequence;
    }

    /**
     * @param list<int>    $mediaIds
     * @param list<string> $imagePaths
     */
    private static function buildSeedSource(
        array $mediaIds,
        array $imagePaths,
        int $slideCount,
        ?string $title,
        ?string $subtitle,
        ?string $seedOverride,
    ): string
    {
        $filteredIds = array_values(array_filter(
            $mediaIds,
            static fn (mixed $mediaId): bool => is_int($mediaId)
        ));

        $normalisedIds = array_map(
            static fn (int $mediaId): string => (string) $mediaId,
            $filteredIds
        );

        $filteredPaths = array_values(array_filter(
            $imagePaths,
            static fn (mixed $path): bool => is_string($path) && trim($path) !== ''
        ));

        $normalisedPaths = array_map(
            static fn (string $path): string => trim($path),
            $filteredPaths
        );

        $titlePart = $title !== null ? trim($title) : '';
        $subtitlePart = $subtitle !== null ? trim($subtitle) : '';

        $payloadParts = [
            implode('|', $normalisedIds),
            implode('|', $normalisedPaths),
            (string) $slideCount,
            $titlePart,
            $subtitlePart,
        ];

        $payload = implode('||', $payloadParts);

        if ($seedOverride !== null) {
            $seedOverride = trim($seedOverride);
            if ($seedOverride !== '') {
                $payload = $seedOverride . '||' . $payload;
            }
        }

        return hash('sha256', $payload, true);
    }
}
