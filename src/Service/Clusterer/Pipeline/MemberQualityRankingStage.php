<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\AbstractClusterScoreHeuristic;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function max;
use function usort;

/**
 * Orders cluster members by combining per-media quality, aesthetics and duplicate penalties.
 */
final class MemberQualityRankingStage extends AbstractClusterScoreHeuristic implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    public function __construct(
        private readonly MemberMediaLookupInterface $mediaLookup,
        private readonly float $qualityBaselineMegapixels = 12.0,
        private readonly float $qualityWeight = 0.7,
        private readonly float $aestheticWeight = 0.3,
        private readonly float $phashPenalty = 0.35,
        private readonly float $dhashPenalty = 0.25,
        private readonly float $burstPenalty = 0.15,
    ) {
    }

    public function getLabel(): string
    {
        return 'Medienbewertung';
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return false;
    }

    /**
     * @param array<int, Media> $mediaMap
     */
    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
    }

    public function score(ClusterDraft $cluster): float
    {
        return 0.0;
    }

    public function weightKey(): string
    {
        return 'member_quality';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);
        if ($total === 0) {
            if ($progress !== null) {
                $progress(0, 0);
            }

            return $drafts;
        }

        /** @var array<int,true> $memberIds */
        $memberIds = [];
        foreach ($drafts as $draft) {
            foreach ($draft->getMembers() as $id) {
                $memberIds[(int) $id] = true;
            }
        }

        $mediaMap = $this->buildMediaMap(array_map(static fn (int $id): int => $id, array_keys($memberIds)));

        if ($progress !== null) {
            $progress(0, $total);
        }

        $processed = 0;
        foreach ($drafts as $draft) {
            $members = $draft->getMembers();
            if ($members === []) {
                ++$processed;
                continue;
            }

            $params          = $draft->getParams();
            $avgQuality      = $this->floatOrNull($params['quality_avg'] ?? null);
            $avgAesthetics   = $this->floatOrNull($params['aesthetics_score'] ?? null);
            $avgResolution   = $this->floatOrNull($params['quality_resolution'] ?? null);
            $avgSharpness    = $this->floatOrNull($params['quality_sharpness'] ?? null);
            $avgIso          = $this->floatOrNull($params['quality_iso'] ?? null);

            /** @var array<string,array{score:float,quality:float,aesthetics:float,penalty:float}> $details */
            $details = [];
            /** @var array<string,int> $seenPhash */
            $seenPhash = [];
            /** @var array<string,int> $seenDhash */
            $seenDhash = [];
            /** @var array<string,int> $seenBurst */
            $seenBurst = [];

            foreach ($members as $memberId) {
                $media = $mediaMap[(int) $memberId] ?? null;
                if (!$media instanceof Media) {
                    $details[(string) $memberId] = [
                        'score'       => 0.0,
                        'quality'     => 0.0,
                        'aesthetics'  => 0.0,
                        'penalty'     => 0.0,
                    ];
                    continue;
                }

                $qualityScore    = $this->computeQualityComponent($media, $avgQuality, $avgResolution, $avgSharpness, $avgIso);
                $aestheticScore  = $this->computeAestheticComponent($media, $avgAesthetics);
                $duplicateFactor = $this->computeDuplicatePenalty($media, $seenPhash, $seenDhash, $seenBurst);

                $baseScore = ($this->qualityWeight * $qualityScore) + ($this->aestheticWeight * $aestheticScore);
                if ($duplicateFactor > 0.0) {
                    $baseScore *= max(0.0, 1.0 - $duplicateFactor);
                }

                $details[(string) $memberId] = [
                    'score'       => $this->clamp01($baseScore),
                    'quality'     => $qualityScore,
                    'aesthetics'  => $aestheticScore,
                    'penalty'     => $duplicateFactor,
                ];
            }

            $positions = [];
            foreach ($members as $idx => $memberId) {
                $positions[(int) $memberId] = $idx;
            }

            $qualityOrdered = $members;
            usort($qualityOrdered, function (int $a, int $b) use ($details, $positions): int {
                $detailA = $details[(string) $a]['score'] ?? 0.0;
                $detailB = $details[(string) $b]['score'] ?? 0.0;

                if ($detailA === $detailB) {
                    return ($positions[$a] ?? 0) <=> ($positions[$b] ?? 0);
                }

                return $detailA < $detailB ? 1 : -1;
            });

            $ranked = [];
            foreach ($qualityOrdered as $memberId) {
                $detail   = $details[(string) $memberId];
                $ranked[] = [
                    'id'          => $memberId,
                    'score'       => $detail['score'],
                    'quality'     => $detail['quality'],
                    'aesthetics'  => $detail['aesthetics'],
                    'penalty'     => $detail['penalty'],
                ];
            }

            $draft->setParam('member_quality', [
                'ordered' => array_values($members),
                'members' => $details,
                'quality_ranked' => [
                    'ordered' => $qualityOrdered,
                    'members' => $ranked,
                ],
                'summary' => [
                    'quality_avg'       => $avgQuality,
                    'aesthetics_avg'    => $avgAesthetics,
                    'quality_resolution'=> $avgResolution,
                    'quality_sharpness' => $avgSharpness,
                    'quality_iso'       => $avgIso,
                ],
                'weights' => [
                    'quality'    => $this->qualityWeight,
                    'aesthetics' => $this->aestheticWeight,
                    'duplicates' => [
                        'phash' => $this->phashPenalty,
                        'dhash' => $this->dhashPenalty,
                        'burst' => $this->burstPenalty,
                    ],
                ],
            ]);

            ++$processed;
            if ($progress !== null && ($processed % 200) === 0) {
                $progress($processed, $total);
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        return $drafts;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Media>
     */
    private function buildMediaMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach ($this->mediaLookup->findByIds($ids) as $media) {
            $map[$media->getId()] = $media;
        }

        return $map;
    }

    private function computeQualityComponent(
        Media $media,
        ?float $avgQuality,
        ?float $avgResolution,
        ?float $avgSharpness,
        ?float $avgIso,
    ): float {
        $score = $media->getQualityScore();

        if ($score === null) {
            $resolution = $this->resolveResolutionScore($media);
            if ($resolution === null) {
                $resolution = $avgResolution;
            }

            $sharpness = $media->getSharpness();
            $sharpness = $sharpness !== null ? $this->clamp01($sharpness) : $avgSharpness;

            $isoScore = $media->getQualityNoise();
            if ($isoScore !== null) {
                $isoScore = $this->clamp01($isoScore);
            } else {
                $isoValue = $media->getIso();
                if ($isoValue !== null && $isoValue > 0) {
                    $isoScore = $this->normalizeIso($isoValue);
                } elseif ($avgIso !== null) {
                    $isoScore = $avgIso;
                }
            }

            $score = $this->combineScores([
                [$resolution, 0.45],
                [$sharpness, 0.35],
                [$isoScore ?? null, 0.20],
            ], $avgQuality ?? 0.0);
        } else {
            $score = $this->clamp01($score);
        }

        if ($avgQuality !== null && $avgQuality > 0.0) {
            $relative = $this->clamp01($score / max(0.0001, $avgQuality));
            $score    = $this->clamp01(($score * 0.6) + ($relative * 0.4));
        }

        return $this->clamp01($score);
    }

    private function computeAestheticComponent(Media $media, ?float $avgAesthetics): float
    {
        $entropy = $media->getEntropy();
        $color   = $media->getColorfulness();

        $components = [];
        $exposure   = $media->getQualityExposure();
        if ($exposure !== null) {
            $components[] = [$this->clamp01($exposure), 0.50];
        } else {
            $brightness = $media->getBrightness();
            $contrast   = $media->getContrast();

            $components[] = [$brightness !== null ? $this->balancedScore($this->clamp01($brightness), 0.55, 0.35) : null, 0.30];
            $components[] = [$contrast !== null ? $this->clamp01($contrast) : null, 0.20];
        }

        $components[] = [$entropy !== null ? $this->clamp01($entropy) : null, 0.25];
        $components[] = [$color !== null ? $this->clamp01($color) : null, 0.25];

        $score = $this->combineScores($components, $avgAesthetics ?? 0.0);

        if ($avgAesthetics !== null && $avgAesthetics > 0.0) {
            $relative = $this->clamp01($score / max(0.0001, $avgAesthetics));
            $score    = $this->clamp01(($score * 0.6) + ($relative * 0.4));
        }

        return $this->clamp01($score);
    }

    /**
     * @param array<string,int> $seenPhash
     * @param array<string,int> $seenDhash
     * @param array<string,int> $seenBurst
     */
    private function computeDuplicatePenalty(Media $media, array &$seenPhash, array &$seenDhash, array &$seenBurst): float
    {
        $penalty  = $this->registerDuplicate($this->stringOrNull($media->getPhash()), $seenPhash, $this->phashPenalty);
        $penalty += $this->registerDuplicate($this->stringOrNull($media->getDhash()), $seenDhash, $this->dhashPenalty);
        $penalty += $this->registerDuplicate($this->stringOrNull($media->getBurstUuid()), $seenBurst, $this->burstPenalty);

        return $penalty > 0.9 ? 0.9 : $penalty;
    }

    /**
     * @param array<string,int> $seen
     */
    private function registerDuplicate(?string $value, array &$seen, float $basePenalty): float
    {
        if ($value === null) {
            return 0.0;
        }

        $count      = $seen[$value] ?? 0;
        $seen[$value] = $count + 1;

        if ($count === 0) {
            return 0.0;
        }

        $penalty = $basePenalty * (1.0 + 0.5 * ($count - 1));

        return $penalty > 0.9 ? 0.9 : $penalty;
    }

    private function resolveResolutionScore(Media $media): ?float
    {
        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        $megapixels = ((float) $width * (float) $height) / 1_000_000.0;

        return $this->clamp01($megapixels / max(0.000001, $this->qualityBaselineMegapixels));
    }
}
