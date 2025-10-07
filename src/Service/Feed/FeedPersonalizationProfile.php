<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

/**
 * Value object describing personalisation thresholds for feed selection.
 */
final class FeedPersonalizationProfile
{
    public function __construct(
        private readonly string $key,
        private readonly float $minScore,
        private readonly int $minMembers,
        private readonly int $maxPerDay,
        private readonly int $maxTotal,
        private readonly int $maxPerAlgorithm,
        private readonly float $qualityFloor,
        private readonly float $peopleCoverageThreshold,
        private readonly int $recentDays,
        private readonly int $staleDays,
        private readonly float $recentScoreBonus,
        private readonly float $staleScorePenalty,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getMinScore(): float
    {
        return $this->minScore;
    }

    public function getMinMembers(): int
    {
        return $this->minMembers;
    }

    public function getMaxPerDay(): int
    {
        return $this->maxPerDay;
    }

    public function getMaxTotal(): int
    {
        return $this->maxTotal;
    }

    public function getMaxPerAlgorithm(): int
    {
        return $this->maxPerAlgorithm;
    }

    public function getQualityFloor(): float
    {
        return $this->qualityFloor;
    }

    public function getPeopleCoverageThreshold(): float
    {
        return $this->peopleCoverageThreshold;
    }

    public function getRecentDays(): int
    {
        return $this->recentDays;
    }

    public function getStaleDays(): int
    {
        return $this->staleDays;
    }

    public function getRecentScoreBonus(): float
    {
        return $this->recentScoreBonus;
    }

    public function getStaleScorePenalty(): float
    {
        return $this->staleScorePenalty;
    }

    public function adjustScoreForAge(float $score, ?int $ageInDays): float
    {
        if ($ageInDays === null) {
            return $score;
        }

        $adjusted = $score;
        if ($ageInDays <= $this->recentDays) {
            $adjusted += $this->recentScoreBonus;
        }

        if ($ageInDays >= $this->staleDays) {
            $adjusted -= $this->staleScorePenalty;
        }

        if ($adjusted < 0.0) {
            return 0.0;
        }

        return $adjusted;
    }

    /**
     * @return array<string, float|int|string>
     */
    public function describe(): array
    {
        return [
            'profil'                     => $this->key,
            'scoreMinimum'               => $this->minScore,
            'scoreBonusAktuell'          => $this->recentScoreBonus,
            'scoreAbschlagAlt'           => $this->staleScorePenalty,
            'tageAktuell'                => $this->recentDays,
            'tageAlt'                    => $this->staleDays,
            'minMitglieder'              => $this->minMembers,
            'maxProTag'                  => $this->maxPerDay,
            'maxGesamt'                  => $this->maxTotal,
            'maxProAlgorithmus'          => $this->maxPerAlgorithm,
            'qualitaetsMinimum'          => $this->qualityFloor,
            'personenAbdeckungMinimum'   => $this->peopleCoverageThreshold,
        ];
    }
}
