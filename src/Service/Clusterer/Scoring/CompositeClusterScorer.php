<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

final class CompositeClusterScorer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HolidayResolverInterface $holidayResolver,
        private readonly NoveltyHeuristic $novelty,
        /**
         * @var array{
         *     quality:float,
         *     aesthetics:float,
         *     people:float,
         *     content:float,
         *     density:float,
         *     novelty:float,
         *     holiday:float,
         *     recency:float,
         *     location:float,
         *     poi?:float,
         *     time_coverage:float
         * }
         */
        private readonly array $weights = [
            'quality'        => 0.22,
            'aesthetics'     => 0.10,
            'people'         => 0.15,
            'content'        => 0.10,
            'density'        => 0.08,
            'novelty'        => 0.08,
            'holiday'        => 0.07,
            'recency'        => 0.10,
            'location'       => 0.05,
            'poi'            => 0.03,
            'time_coverage'  => 0.02,
        ],
        /** @var array<string,float> $algorithmBoosts */
        private readonly array $algorithmBoosts = [],
        /** @var array<string,float> $poiCategoryBoosts */
        private readonly array $poiCategoryBoosts = [],
        private readonly float $qualityBaselineMegapixels = 12.0,
        private readonly int $minValidYear = 1990,
        private readonly int $timeRangeMinSamples = 3,
        private readonly float $timeRangeMinCoverage = 0.6
    ) {
        foreach ($this->algorithmBoosts as $algorithm => $boost) {
            if ($boost <= 0.0) {
                throw new \InvalidArgumentException(
                    \sprintf('Algorithm boost must be > 0.0, got %s => %f', (string) $algorithm, $boost)
                );
            }
        }

        foreach ($this->poiCategoryBoosts as $pattern => $boost) {
            if (!\is_string($pattern) || $pattern === '') {
                throw new \InvalidArgumentException('POI category boost keys must be non-empty strings.');
            }
            if (!\is_numeric($boost)) {
                throw new \InvalidArgumentException(
                    \sprintf('POI category boost for %s must be numeric.', $pattern)
                );
            }
        }
    }

    /**
     * @param list<ClusterDraft> $clusters
     * @return list<ClusterDraft>
     */
    public function score(array $clusters): array
    {
        if ($clusters === []) {
            return [];
        }

        $mediaMap     = $this->loadMediaMap($clusters);
        $noveltyStats = $this->novelty->buildCorpusStats($mediaMap);
        $now          = \time();

        foreach ($clusters as $c) {
            $params       = $c->getParams();
            $membersCount = \count($c->getMembers());
            $mediaItems   = $this->collectMediaItems($c, $mediaMap);

            // --- ensure valid time_range (try to reconstruct if invalid)
            /** @var array{from:int,to:int}|null $tr */
            $tr = (\is_array($params['time_range'] ?? null)) ? $params['time_range'] : null;
            if (!$this->isValidTimeRange($tr)) {
                $re = $this->computeTimeRangeFromItems($mediaItems);
                if ($re !== null) {
                    $tr = $re;
                    $c->setParam('time_range', $re);
                } else {
                    $tr = null;
                }
            }

            // --- quality & aesthetics
            $qualityMetrics = $this->computeQualityMetrics($mediaItems);
            $c->setParam('quality_avg', $qualityMetrics['resolution_score']);
            $c->setParam('quality_resolution', $qualityMetrics['resolution_score']);
            $c->setParam('quality_resolution_samples', $qualityMetrics['resolution_samples']);
            $c->setParam('quality_sharpness', $qualityMetrics['sharpness_score']);
            $c->setParam('quality_sharpness_samples', $qualityMetrics['sharpness_samples']);
            $c->setParam('quality_iso_score', $qualityMetrics['iso_score']);
            $c->setParam('quality_iso_samples', $qualityMetrics['iso_samples']);
            $c->setParam('quality_score', $qualityMetrics['quality_score']);
            $c->setParam('aesthetics_score', $qualityMetrics['aesthetics_score']);
            $c->setParam('aesthetics_brightness', $qualityMetrics['brightness_score']);
            $c->setParam('aesthetics_brightness_avg', $qualityMetrics['brightness_avg']);
            $c->setParam('aesthetics_contrast', $qualityMetrics['contrast_score']);
            $c->setParam('aesthetics_contrast_avg', $qualityMetrics['contrast_avg']);
            $c->setParam('aesthetics_entropy', $qualityMetrics['entropy_score']);
            $c->setParam('aesthetics_entropy_avg', $qualityMetrics['entropy_avg']);
            $c->setParam('aesthetics_colorfulness', $qualityMetrics['colorfulness_score']);
            $c->setParam('aesthetics_colorfulness_avg', $qualityMetrics['colorfulness_avg']);

            // --- people
            $peopleMetrics = $this->computePeopleMetrics($mediaItems, $membersCount);
            $people        = $peopleMetrics['score'];
            $c->setParam('people', $people);
            $c->setParam('people_count', $peopleMetrics['total_mentions']);
            $c->setParam('people_unique', $peopleMetrics['unique_people']);
            $c->setParam('people_coverage', $peopleMetrics['coverage']);

            // --- density (only with valid time)
            $density = 0.0;
            if ($tr !== null) {
                $duration = \max(1, (int) $tr['to'] - (int) $tr['from']);
                $n        = \max(1, $membersCount);
                $density  = \min(1.0, $n / \max(60.0, (float) $duration / 60.0));
                $c->setParam('density', $density);
            }

            // --- novelty
            $novelty = (float) ($params['novelty'] ?? $this->novelty->computeNovelty($c, $mediaMap, $noveltyStats));
            $c->setParam('novelty', $novelty);

            // --- holiday (only with valid time)
            $holiday = 0.0;
            if ($tr !== null) {
                $holiday = $this->computeHolidayScore((int) $tr['from'], (int) $tr['to']);
                $c->setParam('holiday', $holiday);
            }

            // --- recency (only with valid time; neutral=0.0 wenn unbekannt)
            $recency = 0.0;
            if ($tr !== null) {
                $ageDays = \max(0.0, ($now - (int) $tr['to']) / 86400.0);
                $recency = \max(0.0, 1.0 - \min(1.0, $ageDays / 365.0));
            }
            $c->setParam('recency', $recency);

            // --- poi context (only available when strategies attached Overpass metadata)
            $poiScore = $this->computePoiScore($c);
            $c->setParam('poi_score', $poiScore);

            // --- content & keywords
            $contentMetrics = $this->computeContentMetrics($mediaItems, $membersCount, $params);
            $contentScore   = $contentMetrics['score'];
            $c->setParam('content', $contentScore);
            $c->setParam('content_keywords_unique', $contentMetrics['unique_keywords']);
            $c->setParam('content_keywords_total', $contentMetrics['total_keywords']);
            $c->setParam('content_coverage', $contentMetrics['coverage']);

            // --- location quality
            $locationMetrics = $this->computeLocationMetrics($mediaItems, $membersCount);
            $locationScore   = $locationMetrics['score'];
            $c->setParam('location_score', $locationScore);
            $c->setParam('location_geo_coverage', $locationMetrics['geo_coverage']);

            // --- temporal coverage
            $temporalMetrics = $this->computeTemporalMetrics($mediaItems, $membersCount, $tr);
            $temporalScore   = $temporalMetrics['score'];
            $c->setParam('temporal_score', $temporalScore);
            $c->setParam('temporal_coverage', $temporalMetrics['coverage']);
            $c->setParam('temporal_duration_seconds', $temporalMetrics['duration_seconds']);
            $c->setParam('temporal_duration_minutes', $temporalMetrics['duration_seconds'] / 60.0);

            // --- weighted sum
            $score =
                ($this->weights['quality'] ?? 0.0)       * $qualityMetrics['quality_score'] +
                ($this->weights['aesthetics'] ?? 0.0)    * $qualityMetrics['aesthetics_score'] +
                ($this->weights['people'] ?? 0.0)        * $people +
                ($this->weights['content'] ?? 0.0)       * $contentScore +
                ($this->weights['density'] ?? 0.0)       * $density +
                ($this->weights['novelty'] ?? 0.0)       * $novelty +
                ($this->weights['holiday'] ?? 0.0)       * $holiday +
                ($this->weights['recency'] ?? 0.0)       * $recency +
                ($this->weights['location'] ?? 0.0)      * $locationScore +
                ($this->weights['poi'] ?? 0.0)           * $poiScore +
                ($this->weights['time_coverage'] ?? 0.0) * $temporalScore;

            $algorithm = $c->getAlgorithm();
            $boost     = $this->algorithmBoosts[$algorithm] ?? 1.0;
            if ($boost !== 1.0) {
                $score *= $boost;
                $c->setParam('score_algorithm_boost', $boost);
            }

            $c->setParam('score', $score);
        }

        \usort($clusters, static function (ClusterDraft $a, ClusterDraft $b): int {
            return ($b->getParams()['score'] ?? 0.0) <=> ($a->getParams()['score'] ?? 0.0);
        });

        return $clusters;
    }

    /**
     * @param list<Media> $mediaItems
     */
    private function computeQualityMetrics(array $mediaItems): array
    {
        $resolutionSum   = 0.0;
        $resolutionCount = 0;
        $sharpnessSum    = 0.0;
        $sharpnessCount  = 0;
        $isoScoreSum     = 0.0;
        $isoCount        = 0;

        $brightnessSum  = 0.0;
        $brightnessCount = 0;
        $contrastSum    = 0.0;
        $contrastCount  = 0;
        $entropySum     = 0.0;
        $entropyCount   = 0;
        $colorSum       = 0.0;
        $colorCount     = 0;

        foreach ($mediaItems as $media) {
            $w = $media->getWidth();
            $h = $media->getHeight();
            if ($w !== null && $h !== null && $w > 0 && $h > 0) {
                $mp = ((float) $w * (float) $h) / 1_000_000.0;
                $resolutionSum += $this->clamp01($mp / \max(1e-6, $this->qualityBaselineMegapixels));
                $resolutionCount++;
            }

            $sharpness = $media->getSharpness();
            if ($sharpness !== null) {
                $sharpnessSum += $this->clamp01($sharpness);
                $sharpnessCount++;
            }

            $iso = $media->getIso();
            if ($iso !== null && $iso > 0) {
                $isoScoreSum += $this->normalizeIso($iso);
                $isoCount++;
            }

            $brightness = $media->getBrightness();
            if ($brightness !== null) {
                $brightnessSum += $this->clamp01($brightness);
                $brightnessCount++;
            }

            $contrast = $media->getContrast();
            if ($contrast !== null) {
                $contrastSum += $this->clamp01($contrast);
                $contrastCount++;
            }

            $entropy = $media->getEntropy();
            if ($entropy !== null) {
                $entropySum += $this->clamp01($entropy);
                $entropyCount++;
            }

            $colorfulness = $media->getColorfulness();
            if ($colorfulness !== null) {
                $colorSum += $this->clamp01($colorfulness);
                $colorCount++;
            }
        }

        $resolutionScore = $resolutionCount > 0 ? $resolutionSum / $resolutionCount : 0.5;
        $sharpnessScore  = $sharpnessCount > 0 ? $sharpnessSum / $sharpnessCount : 0.5;
        $isoScoreValue   = $isoCount > 0 ? $isoScoreSum / $isoCount : null;

        $brightnessAvg = $brightnessCount > 0 ? $brightnessSum / $brightnessCount : null;
        $contrastAvg   = $contrastCount > 0 ? $contrastSum / $contrastCount : null;
        $entropyAvg    = $entropyCount > 0 ? $entropySum / $entropyCount : null;
        $colorAvg      = $colorCount > 0 ? $colorSum / $colorCount : null;

        $brightnessScore = $brightnessAvg !== null ? $this->balancedScore($brightnessAvg, 0.55, 0.45) : null;
        $contrastScore   = $contrastAvg !== null ? $contrastAvg : null;
        $entropyScore    = $entropyAvg !== null ? $entropyAvg : null;
        $colorScore      = $colorAvg !== null ? $colorAvg : null;

        $qualityScore = $this->combineScores([
            [$resolutionScore, 0.4],
            [$sharpnessScore, 0.35],
            [$isoScoreValue, 0.25],
        ], 0.5);

        $aestheticScore = $this->combineScores([
            [$brightnessScore, 0.25],
            [$contrastScore, 0.25],
            [$entropyScore, 0.25],
            [$colorScore, 0.25],
        ], 0.5);

        return [
            'quality_score'          => $qualityScore,
            'aesthetics_score'       => $aestheticScore,
            'resolution_score'       => $resolutionScore,
            'resolution_samples'     => $resolutionCount,
            'sharpness_score'        => $sharpnessScore,
            'sharpness_samples'      => $sharpnessCount,
            'iso_score'              => $isoScoreValue ?? 0.6,
            'iso_samples'            => $isoCount,
            'brightness_score'       => $brightnessScore ?? 0.5,
            'brightness_avg'         => $brightnessAvg,
            'contrast_score'         => $contrastScore ?? 0.5,
            'contrast_avg'           => $contrastAvg,
            'entropy_score'          => $entropyScore ?? 0.5,
            'entropy_avg'            => $entropyAvg,
            'colorfulness_score'     => $colorScore ?? 0.5,
            'colorfulness_avg'       => $colorAvg,
        ];
    }

    /**
     * @param list<array{0:float|null,1:float}> $components
     */
    private function combineScores(array $components, float $default = 0.0): float
    {
        $sum   = 0.0;
        $total = 0.0;

        foreach ($components as [$value, $weight]) {
            if ($value === null) {
                continue;
            }
            $sum   += $this->clamp01($value) * $weight;
            $total += $weight;
        }

        if ($total <= 0.0) {
            return $default;
        }

        return $sum / $total;
    }

    private function balancedScore(float $value, float $target, float $tolerance): float
    {
        $delta = \abs($value - $target);
        if ($delta >= $tolerance) {
            return 0.0;
        }

        return $this->clamp01(1.0 - ($delta / $tolerance));
    }

    private function normalizeIso(int $iso): float
    {
        $min = 50.0;
        $max = 6400.0;
        $iso = (float) \max($min, \min($max, $iso));
        $ratio = \log($iso / $min) / \log($max / $min);

        return $this->clamp01(1.0 - $ratio);
    }

    /**
     * @param list<Media> $mediaItems
     * @return array{score:float,unique_people:int,total_mentions:int,coverage:float}
     */
    private function computePeopleMetrics(array $mediaItems, int $totalMembers): array
    {
        $unique = [];
        $mentions = 0;
        $itemsWithPersons = 0;

        foreach ($mediaItems as $media) {
            $added = false;

            if (\method_exists($media, 'getPersonIds')) {
                /** @var list<int> $ids */
                $ids = (array) $media->getPersonIds();
                if ($ids !== []) {
                    $itemsWithPersons++;
                    foreach ($ids as $id) {
                        $unique['id:' . (string) $id] = true;
                    }
                    $mentions += \count($ids);
                    $added = true;
                }
            }

            if ($added) {
                continue;
            }

            $persons = $media->getPersons();
            if (\is_array($persons) && $persons !== []) {
                $itemsWithPersons++;
                foreach ($persons as $name) {
                    $name = \trim((string) $name);
                    if ($name === '') {
                        continue;
                    }
                    $unique['name:' . \strtolower($name)] = true;
                    $mentions++;
                }
            }
        }

        $coverage      = $totalMembers > 0 ? $itemsWithPersons / $totalMembers : 0.0;
        $uniqueCount   = \count($unique);
        $diversity     = $uniqueCount > 0 ? \min(1.0, $uniqueCount / 4.0) : 0.0;
        $densityScore  = $totalMembers > 0 ? \min(1.0, ($mentions / \max(1, $totalMembers)) / 2.0) : 0.0;

        $score = $this->combineScores([
            [$coverage, 0.5],
            [$diversity, 0.3],
            [$densityScore, 0.2],
        ]);

        return [
            'score'           => $score,
            'unique_people'   => $uniqueCount,
            'total_mentions'  => $mentions,
            'coverage'        => $this->clamp01($coverage),
        ];
    }

    /**
     * @param list<Media> $mediaItems
     * @param array<string,mixed> $params
     * @return array{score:float,unique_keywords:int,total_keywords:int,coverage:float}
     */
    private function computeContentMetrics(array $mediaItems, int $totalMembers, array $params): array
    {
        $keywordSet    = [];
        $keywordTotal  = 0;
        $itemsWithTags = 0;

        foreach ($mediaItems as $media) {
            $keywords = $media->getKeywords();
            if (!\is_array($keywords) || $keywords === []) {
                continue;
            }

            $itemsWithTags++;
            foreach ($keywords as $keyword) {
                $normalized = \strtolower(\trim((string) $keyword));
                if ($normalized === '') {
                    continue;
                }
                $keywordSet[$normalized] = true;
                $keywordTotal++;
            }
        }

        if (\is_array($params['keywords'] ?? null)) {
            foreach ($params['keywords'] as $keyword) {
                $normalized = \strtolower(\trim((string) $keyword));
                if ($normalized === '') {
                    continue;
                }
                $keywordSet[$normalized] = true;
            }
        }

        $coverage = $totalMembers > 0 ? $itemsWithTags / $totalMembers : 0.0;
        $unique   = \count($keywordSet);
        $diversityScore = $unique > 0 ? \min(1.0, $unique / 6.0) : 0.0;

        $score = $this->combineScores([
            [$coverage, 0.55],
            [$diversityScore, 0.45],
        ]);

        return [
            'score'           => $score,
            'unique_keywords' => $unique,
            'total_keywords'  => $keywordTotal,
            'coverage'        => $this->clamp01($coverage),
        ];
    }

    /**
     * @param list<Media> $mediaItems
     * @return array{score:float,geo_coverage:float}
     */
    private function computeLocationMetrics(array $mediaItems, int $totalMembers): array
    {
        $gpsItems = [];
        foreach ($mediaItems as $media) {
            if ($media->getGpsLat() !== null && $media->getGpsLon() !== null) {
                $gpsItems[] = $media;
            }
        }

        $geoCoverage = $totalMembers > 0 ? \count($gpsItems) / $totalMembers : 0.0;
        $compactness = null;

        if (\count($gpsItems) >= 2) {
            $centroid = MediaMath::centroid($gpsItems);
            $sum = 0.0;
            foreach ($gpsItems as $media) {
                $sum += MediaMath::haversineDistanceInMeters(
                    (float) $centroid['lat'],
                    (float) $centroid['lon'],
                    (float) $media->getGpsLat(),
                    (float) $media->getGpsLon()
                );
            }
            $avgDistance = $sum / \count($gpsItems);
            $compactness = $this->clamp01(1.0 / (1.0 + $avgDistance / 1000.0));
        }

        $score = $this->combineScores([
            [$geoCoverage, 0.7],
            [$compactness, 0.3],
        ]);

        return [
            'score'        => $score,
            'geo_coverage' => $this->clamp01($geoCoverage),
        ];
    }

    /**
     * @param list<Media> $mediaItems
     * @param array{from:int,to:int}|null $timeRange
     * @return array{score:float,coverage:float,duration_seconds:int}
     */
    private function computeTemporalMetrics(array $mediaItems, int $totalMembers, ?array $timeRange): array
    {
        $timestamped = 0;
        foreach ($mediaItems as $media) {
            if ($media->getTakenAt() instanceof DateTimeImmutable) {
                $timestamped++;
            }
        }

        $coverage = $totalMembers > 0 ? $timestamped / $totalMembers : 0.0;
        $durationSeconds = 0;
        if ($timeRange !== null) {
            $durationSeconds = \max(0, (int) $timeRange['to'] - (int) $timeRange['from']);
        }

        $durationScore = $durationSeconds > 0 ? $this->normalizeDuration($durationSeconds) : null;
        $score = $this->combineScores([
            [$coverage, 0.6],
            [$durationScore, 0.4],
        ]);

        return [
            'score'             => $score,
            'coverage'          => $this->clamp01($coverage),
            'duration_seconds'  => $durationSeconds,
        ];
    }

    private function normalizeDuration(int $seconds): float
    {
        if ($seconds <= 0) {
            return 0.0;
        }

        $scale = 3 * 3600.0; // ~3 hours to reach ~0.95
        $score = 1.0 - \exp(-$seconds / $scale);

        return $this->clamp01($score);
    }

    /**
     * @return list<Media>
     */
    private function collectMediaItems(ClusterDraft $cluster, array $mediaMap): array
    {
        $items = [];
        foreach ($cluster->getMembers() as $id) {
            $media = $mediaMap[$id] ?? null;
            if ($media instanceof Media) {
                $items[] = $media;
            }
        }

        return $items;
    }

    /** @return array<int, Media> */
    private function loadMediaMap(array $clusters): array
    {
        $ids = [];
        foreach ($clusters as $c) {
            foreach ($c->getMembers() as $id) {
                $ids[$id] = true;
            }
        }
        $allIds = \array_map(static fn (int $k): int => $k, \array_keys($ids));
        if ($allIds === []) {
            return [];
        }

        $map = [];
        $chunk = 1000;
        for ($i = 0, $n = \count($allIds); $i < $n; $i += $chunk) {
            $slice = \array_slice($allIds, $i, $chunk);
            $qb = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Media::class, 'm')
                ->where('m.id IN (:ids)')
                ->setParameter('ids', $slice);
            /** @var list<Media> $rows */
            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $m) {
                $map[$m->getId()] = $m;
            }
        }
        return $map;
    }

    private function isValidTimeRange(?array $tr): bool
    {
        if (!\is_array($tr) || !isset($tr['from'], $tr['to'])) {
            return false;
        }
        $from = (int) $tr['from'];
        $to   = (int) $tr['to'];
        if ($from <= 0 || $to <= 0 || $to < $from) {
            return false;
        }
        $minTs = (int) (new DateTimeImmutable(\sprintf('%04d-01-01', $this->minValidYear)))->getTimestamp();
        return $from >= $minTs && $to >= $minTs;
    }

    /** @param list<Media> $mediaItems @return array{from:int,to:int}|null */
    private function computeTimeRangeFromItems(array $mediaItems): ?array
    {
        if ($mediaItems === []) {
            return null;
        }

        return MediaMath::timeRangeReliable(
            $mediaItems,
            $this->timeRangeMinSamples,
            $this->timeRangeMinCoverage,
            $this->minValidYear
        );
    }

    private function computePoiScore(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();
        $label = $this->stringOrNull($params['poi_label'] ?? null);
        $categoryKey = $this->stringOrNull($params['poi_category_key'] ?? null);
        $categoryValue = $this->stringOrNull($params['poi_category_value'] ?? null);
        $tags = \is_array($params['poi_tags'] ?? null) ? $params['poi_tags'] : [];

        $score = 0.0;

        if ($label !== null) {
            $score += 0.45;
        }

        if ($categoryKey !== null || $categoryValue !== null) {
            $score += 0.25;
        }

        $score += $this->lookupPoiCategoryBoost($categoryKey, $categoryValue);

        if (\is_array($tags)) {
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
            $boost += (float) ($this->poiCategoryBoosts[$categoryKey.'/*'] ?? 0.0);
        }

        if ($categoryValue !== null) {
            $boost += (float) ($this->poiCategoryBoosts['*/'.$categoryValue] ?? 0.0);
        }

        if ($categoryKey !== null && $categoryValue !== null) {
            $boost += (float) ($this->poiCategoryBoosts[$categoryKey.'/'.$categoryValue] ?? 0.0);
        }

        return $boost;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function clamp01(float $value): float
    {
        if ($value <= 0.0) {
            return 0.0;
        }

        if ($value >= 1.0) {
            return 1.0;
        }

        return $value;
    }

    private function computeHolidayScore(int $fromTs, int $toTs): float
    {
        // guard against swapped or absurd ranges (should already be filtered)
        if ($toTs < $fromTs) {
            return 0.0;
        }
        $start = (new \DateTimeImmutable('@' . $fromTs))->setTime(0, 0);
        $end   = (new \DateTimeImmutable('@' . $toTs))->setTime(0, 0);

        $onHoliday = false;
        $onWeekend = false;

        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            if ($this->holidayResolver->isHoliday($d)) {
                $onHoliday = true;
                break;
            }
            $dow = (int) $d->format('N'); // 6=Sat, 7=Sun
            if ($dow >= 6) {
                $onWeekend = true;
            }
        }

        if ($onHoliday) {
            return 1.0;
        }
        if ($onWeekend) {
            return 0.5;
        }
        return 0.0;
    }

    private function computeQualityAvg(ClusterDraft $c, array $mediaMap): float
    {
        $sum = 0.0;
        $n   = 0;
        foreach ($c->getMembers() as $id) {
            $m = $mediaMap[$id] ?? null;
            if (!$m instanceof Media) {
                continue;
            }
            $w = $m->getWidth();
            $h = $m->getHeight();
            if ($w === null || $h === null || $w <= 0 || $h <= 0) {
                continue;
            }
            $mp   = ((float) $w * (float) $h) / 1_000_000.0;
            $norm = \min(1.0, $mp / \max(1e-6, $this->qualityBaselineMegapixels));
            $sum += $norm;
            $n++;
        }
        return $n > 0 ? $sum / $n : 0.5;
    }
}
