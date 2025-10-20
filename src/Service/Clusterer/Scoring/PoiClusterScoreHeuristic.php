<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

use function abs;
use function array_key_first;
use function array_sum;
use function arsort;
use function hexdec;
use function is_array;
use function is_numeric;
use function max;
use function min;
use function strlen;
use function strtolower;
use function substr;

/**
 * Class PoiClusterScoreHeuristic.
 */
final class PoiClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    /**
     * @param array<string, float>  $poiCategoryBoosts
     * @param array<string, string> $iconicSignatures
     */
    public function __construct(
        private readonly array $poiCategoryBoosts = [],
        private readonly float $iconicBoost = 0.0,
        private readonly float $iconicSimilarityThreshold = 0.8,
        private readonly array $iconicSignatures = [],
    ) {
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params             = $cluster->getParams();
        $existingScore      = $this->floatOrNull($params['poi_score'] ?? null);
        $boostAlreadyStored = (bool) ($params['poi_iconic_boost_applied'] ?? false);

        $score = $existingScore ?? $this->computePoiScore($cluster);

        $iconic = $this->analyseIconicComposition($cluster, $mediaMap);

        if ($iconic['shouldBoost'] && (!$boostAlreadyStored || $existingScore === null)) {
            $score = $this->clamp01($score + $this->iconicBoost);
        }

        $cluster->setParam('poi_iconic_phash', $iconic['hash']);
        $cluster->setParam('poi_iconic_count', $iconic['count']);
        $cluster->setParam('poi_iconic_sample_count', $iconic['total']);
        $cluster->setParam('poi_iconic_ratio', $iconic['ratio']);
        $cluster->setParam('poi_iconic_signature', $iconic['signature']);
        $cluster->setParam('poi_iconic_signature_similarity', $iconic['signatureSimilarity']);
        $cluster->setParam('poi_iconic_boost_applied', $iconic['shouldBoost']);
        $cluster->setParam('poi_iconic_trigger', $iconic['trigger']);
        $cluster->setParam('poi_iconic_is_dominant', $iconic['isDominant']);

        $cluster->setParam('poi_score', $this->clamp01($score));
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['poi_score'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'poi';
    }

    private function computePoiScore(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();
        if (isset($params['poi_score']) && is_numeric($params['poi_score'])) {
            return $this->clamp01((float) $params['poi_score']);
        }

        $label         = $this->stringOrNull($params['poi_label'] ?? null);
        $categoryKey   = $this->stringOrNull($params['poi_category_key'] ?? null);
        $categoryValue = $this->stringOrNull($params['poi_category_value'] ?? null);
        $tags          = is_array($params['poi_tags'] ?? null) ? $params['poi_tags'] : [];

        $score = 0.0;
        if ($label !== null) {
            $score += 0.45;
        }

        if ($categoryKey !== null || $categoryValue !== null) {
            $score += 0.25;
        }

        $score += $this->lookupPoiCategoryBoost($categoryKey, $categoryValue);

        if (is_array($tags)) {
            if ($this->stringOrNull($tags['wikidata'] ?? null) !== null) {
                $score += 0.15;
            }

            if ($this->stringOrNull($tags['website'] ?? null) !== null) {
                $score += 0.05;
            }
        }

        return $this->clamp01($score);
    }

    private function lookupPoiCategoryBoost(?string $categoryKey, ?string $categoryValue): float
    {
        if ($this->poiCategoryBoosts === []) {
            return 0.0;
        }

        $boost = 0.0;

        if ($categoryKey !== null) {
            $boost += (float) ($this->poiCategoryBoosts[$categoryKey . '/*'] ?? 0.0);
        }

        if ($categoryValue !== null) {
            $boost += (float) ($this->poiCategoryBoosts['*/' . $categoryValue] ?? 0.0);
        }

        if ($categoryKey !== null && $categoryValue !== null) {
            $boost += (float) ($this->poiCategoryBoosts[$categoryKey . '/' . $categoryValue] ?? 0.0);
        }

        return $boost;
    }

    /**
     * @param array<int, Media> $mediaMap
     *
     * @return array{hash: ?string, count: int, total: int, ratio: float, signature: ?string, signatureSimilarity: ?float, shouldBoost: bool, trigger: ?string, isDominant: bool}
     */
    private function analyseIconicComposition(ClusterDraft $cluster, array $mediaMap): array
    {
        /** @var list<Media> $mediaItems */
        $mediaItems = $this->collectMediaItems($cluster, $mediaMap);

        $hashCounts = [];
        foreach ($mediaItems as $media) {
            $hash = $this->normalisePhash($media->getPhash());
            if ($hash === null) {
                continue;
            }

            $hashCounts[$hash] = ($hashCounts[$hash] ?? 0) + 1;
        }

        if ($hashCounts === []) {
            return [
                'hash' => null,
                'count' => 0,
                'total' => 0,
                'ratio' => 0.0,
                'signature' => null,
                'signatureSimilarity' => null,
                'shouldBoost' => false,
                'trigger' => null,
                'isDominant' => false,
            ];
        }

        arsort($hashCounts);
        $dominantHash  = array_key_first($hashCounts);
        $dominantCount = (int) ($dominantHash !== null ? $hashCounts[$dominantHash] : 0);
        $totalSamples  = (int) array_sum($hashCounts);
        $ratio         = $totalSamples > 0 ? $dominantCount / $totalSamples : 0.0;

        $signatureMatch = $dominantHash !== null ? $this->matchIconicSignature($dominantHash) : null;

        $isDominant = $dominantCount >= 2 && $ratio >= 0.5;
        $trigger    = null;
        $shouldBoost = false;

        if ($isDominant) {
            if ($signatureMatch !== null && $signatureMatch['similarity'] >= $this->iconicSimilarityThreshold) {
                $shouldBoost = true;
                $trigger     = 'signature';
            } elseif ($ratio >= $this->iconicSimilarityThreshold) {
                $shouldBoost = true;
                $trigger     = 'dominance';
            }
        }

        return [
            'hash' => $dominantHash,
            'count' => $dominantCount,
            'total' => $totalSamples,
            'ratio' => $this->clamp01((float) $ratio),
            'signature' => $signatureMatch['label'] ?? null,
            'signatureSimilarity' => $signatureMatch['similarity'] ?? null,
            'shouldBoost' => $shouldBoost,
            'trigger' => $trigger,
            'isDominant' => $isDominant,
        ];
    }

    /**
     * @return array{label: string, similarity: float}|null
     */
    private function matchIconicSignature(string $hash): ?array
    {
        if ($this->iconicSignatures === []) {
            return null;
        }

        $bestMatch = null;
        foreach ($this->iconicSignatures as $label => $signature) {
            $signatureHash = $this->normalisePhash($signature);
            if ($signatureHash === null) {
                continue;
            }

            $similarity = $this->phashSimilarity($hash, $signatureHash);
            if ($bestMatch === null || $similarity > $bestMatch['similarity']) {
                $bestMatch = [
                    'label' => $label,
                    'similarity' => $similarity,
                ];
            }
        }

        return $bestMatch;
    }

    private function normalisePhash(?string $hash): ?string
    {
        if ($hash === null || $hash === '') {
            return null;
        }

        $normalised = strtolower($hash);
        $length     = max(1, min(16, strlen($normalised)));

        return substr($normalised, 0, $length);
    }

    private function phashSimilarity(string $hashA, string $hashB): float
    {
        $len = min(strlen($hashA), strlen($hashB));
        if ($len <= 0) {
            return 0.0;
        }

        $distance = 0;
        for ($i = 0; $i < $len; ++$i) {
            $distance += $this->bitCount(hexdec($hashA[$i]) ^ hexdec($hashB[$i]));
        }

        $distance += (int) (abs(strlen($hashA) - strlen($hashB)) * 4);

        $maxBits    = max(1, $len * 4);
        $similarity = 1.0 - ($distance / $maxBits);

        return $this->clamp01($similarity);
    }

    private function bitCount(int $value): int
    {
        $bits = 0;
        $v    = $value;

        while ($v > 0) {
            $bits += $v & 1;
            $v >>= 1;
        }

        return $bits;
    }
}
