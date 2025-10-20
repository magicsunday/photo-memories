<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;

use function array_intersect;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function intval;
use function is_array;
use function is_numeric;
use function is_string;
use function min;
use function trim;

/**
 * Aggregates per-media people metrics for cluster level annotations.
 */
final readonly class ClusterPeopleAggregator
{
    private const FAVOURITE_BOOST = 0.35;

    /**
     * @var list<int>
     */
    private array $favouritePersonIds;

    /**
     * @var list<int>
     */
    private array $fallbackPersonIds;

    private PersonSignatureHelper $personHelper;

    /**
     * @param list<int|string> $favouritePersonIds
     * @param list<int|string> $fallbackPersonIds
     */
    public function __construct(
        array $favouritePersonIds = [],
        array $fallbackPersonIds = [],
        ?PersonSignatureHelper $personHelper = null,
    ) {
        $this->personHelper       = $personHelper ?? new PersonSignatureHelper();
        $this->favouritePersonIds = $this->normalisePreferredIds($favouritePersonIds);
        $this->fallbackPersonIds  = $this->normalisePreferredIds($fallbackPersonIds);
    }

    /**
     * Builds the people related parameters for a list of media items.
     *
     * @param list<Media> $mediaItems
     *
     * @return array{
     *     people: float,
     *     people_count: int,
     *     people_unique: int,
     *     people_coverage: float,
     *     people_face_coverage: float,
     *     people_favourite_coverage: float
     * }
     */
    public function buildParams(array $mediaItems): array
    {
        $members = count($mediaItems);

        if ($members === 0) {
            return [
                'people'               => 0.0,
                'people_count'         => 0,
                'people_unique'        => 0,
                'people_coverage'      => 0.0,
                'people_face_coverage' => 0.0,
                'people_favourite_coverage' => 0.0,
            ];
        }

        /** @var array<string, bool> $uniqueNames */
        $uniqueNames = [];
        $mentions    = 0;
        $withPeople  = 0;
        $withFaces   = 0;
        $favouriteMembers  = 0;
        $favouriteMentions = 0;

        $favouriteUniverse = $this->resolveFavouriteUniverse();

        foreach ($mediaItems as $media) {
            if ($media->hasFaces() === true) {
                ++$withFaces;
            }

            $personIds = $favouriteUniverse !== []
                ? $this->personHelper->personIds($media)
                : [];
            $favouriteMatches = $favouriteUniverse !== []
                ? $this->countFavourites($personIds, $favouriteUniverse)
                : 0;

            if ($favouriteMatches > 0) {
                ++$favouriteMembers;
                $favouriteMentions += $favouriteMatches;
            }

            $persons = $media->getPersons();
            $hasPersons = is_array($persons) && $persons !== [];

            if ($hasPersons || ($personIds !== [] && $favouriteMatches > 0)) {
                ++$withPeople;
            }

            if (!$hasPersons) {
                continue;
            }

            foreach ($persons as $person) {
                if (!is_string($person)) {
                    continue;
                }

                $label = trim($person);
                if ($label === '') {
                    continue;
                }

                if (!array_key_exists($label, $uniqueNames)) {
                    $uniqueNames[$label] = true;
                }

                ++$mentions;
            }
        }

        $uniqueCount   = count($uniqueNames);
        $coverage      = $withPeople > 0 ? $withPeople / $members : 0.0;
        $faceCoverage  = $withFaces > 0 ? $withFaces / $members : 0.0;
        $richness      = $uniqueCount > 0 ? min(1.0, $uniqueCount / 4.0) : 0.0;
        $mentionScore  = $mentions > 0 ? min(1.0, $mentions / (float) $members) : 0.0;
        $baseCoverage  = $this->clamp01($coverage);
        $favouriteCoverage = $members > 0 ? $favouriteMembers / $members : 0.0;
        $favouriteMentionShare = $members > 0 ? $favouriteMentions / $members : 0.0;

        $coverageScore = $this->clamp01($baseCoverage + ($favouriteCoverage * self::FAVOURITE_BOOST));
        $mentionScore  = $this->clamp01($mentionScore + ($favouriteMentionShare * self::FAVOURITE_BOOST));
        $favouriteCoverage = $this->clamp01($favouriteCoverage);

        $score = $this->combineScores([
            [$coverageScore, 0.4],
            [$richness, 0.35],
            [$mentionScore, 0.25],
        ]);

        return [
            'people'               => $score,
            'people_count'         => $mentions,
            'people_unique'        => $uniqueCount,
            'people_coverage'      => $baseCoverage,
            'people_face_coverage' => $this->clamp01($faceCoverage),
            'people_favourite_coverage' => $favouriteCoverage,
        ];
    }

    /**
     * @return list<int>
     */
    private function resolveFavouriteUniverse(): array
    {
        if ($this->favouritePersonIds === []) {
            return $this->fallbackPersonIds;
        }

        if ($this->fallbackPersonIds === []) {
            return $this->favouritePersonIds;
        }

        return array_values(array_unique([...$this->favouritePersonIds, ...$this->fallbackPersonIds]));
    }

    /**
     * @param list<int> $personIds
     * @param list<int> $favourites
     */
    private function countFavourites(array $personIds, array $favourites): int
    {
        if ($personIds === [] || $favourites === []) {
            return 0;
        }

        return count(array_intersect($personIds, $favourites));
    }

    /**
     * @param list<int|string> $values
     *
     * @return list<int>
     */
    private function normalisePreferredIds(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                if (is_numeric($trimmed)) {
                    $id = (int) $trimmed;
                } else {
                    $id = $this->personHelper->idFromName($trimmed);
                    if ($id === null) {
                        continue;
                    }
                }
            } else {
                continue;
            }

            if ($id <= 0) {
                continue;
            }

            $result[] = $id;
        }

        return array_values(array_unique($result));
    }

    private function clamp01(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    /**
     * @param array<array{0: float, 1: float}> $components
     */
    private function combineScores(array $components): float
    {
        $sum       = 0.0;
        $weightSum = 0.0;

        foreach ($components as [$value, $weight]) {
            $sum += $this->clamp01($value) * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0.0) {
            return 0.0;
        }

        return $sum / $weightSum;
    }
}
