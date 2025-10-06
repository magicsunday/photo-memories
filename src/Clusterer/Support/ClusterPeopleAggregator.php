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

use function array_key_exists;
use function count;
use function is_array;
use function is_string;
use function min;
use function trim;

/**
 * Aggregates per-media people metrics for cluster level annotations.
 */
final readonly class ClusterPeopleAggregator
{
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
     *     people_face_coverage: float
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
            ];
        }

        /** @var array<string, bool> $uniqueNames */
        $uniqueNames = [];
        $mentions    = 0;
        $withPeople  = 0;
        $withFaces   = 0;

        foreach ($mediaItems as $media) {
            if ($media->hasFaces() === true) {
                ++$withFaces;
            }

            $persons = $media->getPersons();
            if (!is_array($persons) || $persons === []) {
                continue;
            }

            ++$withPeople;

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
        $coverageScore = $this->clamp01($coverage);

        $score = $this->combineScores([
            [$coverageScore, 0.4],
            [$richness, 0.35],
            [$mentionScore, 0.25],
        ]);

        return [
            'people'               => $score,
            'people_count'         => $mentions,
            'people_unique'        => $uniqueCount,
            'people_coverage'      => $coverageScore,
            'people_face_coverage' => $this->clamp01($faceCoverage),
        ];
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
