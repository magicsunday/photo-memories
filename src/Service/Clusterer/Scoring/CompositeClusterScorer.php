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
            'aesthetics'     => 0.08,
            'people'         => 0.16,
            'content'        => 0.09,
            'density'        => 0.10,
            'novelty'        => 0.09,
            'holiday'        => 0.07,
            'recency'        => 0.12,
            'location'       => 0.05,
            'poi'            => 0.02,
            'time_coverage'  => 0.10,
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
                $re = $this->computeTimeRangeFromMembers($c, $mediaMap);
                if ($re !== null) {
                    $tr = $re;
                    $c->setParam('time_range', $re);
                } else {
                    $tr = null;
                }
            }

            // --- quality_avg
            $qualityMetrics = $this->computeQualityMetrics($mediaItems);
            $quality        = (float) ($params['quality_avg'] ?? $qualityMetrics['quality']);
            $aesthetics     = $qualityMetrics['aesthetics'];
            $c->setParam('quality_avg', $quality);
            if ($qualityMetrics['resolution'] !== null) {
                $c->setParam('quality_resolution', $qualityMetrics['resolution']);
            }
            if ($qualityMetrics['sharpness'] !== null) {
                $c->setParam('quality_sharpness', $qualityMetrics['sharpness']);
            }
            if ($qualityMetrics['iso'] !== null) {
                $c->setParam('quality_iso', $qualityMetrics['iso']);
            }
            if ($aesthetics !== null) {
                $c->setParam('aesthetics_score', $aesthetics);
            }

            // --- people
            $peopleMetrics = $this->computePeopleMetrics($mediaItems, $membersCount, $params);
            $people        = $peopleMetrics['score'];
            $c->setParam('people', $people);
            $c->setParam('people_count', $peopleMetrics['mentions']);
            $c->setParam('people_unique', $peopleMetrics['unique']);
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
            $locationMetrics = $this->computeLocationMetrics($mediaItems, $membersCount, $params);
            $locationScore   = $locationMetrics['score'];
            $c->setParam('location_score', $locationScore);
            $c->setParam('location_geo_coverage', $locationMetrics['geo_coverage']);

            // --- temporal coverage
            $temporalMetrics = $this->computeTemporalMetrics($mediaItems, $membersCount, $tr);
            $temporalScore   = $temporalMetrics['score'];
            $c->setParam('temporal_score', $temporalScore);
            $c->setParam('temporal_coverage', $temporalMetrics['coverage']);
            $c->setParam('temporal_duration_seconds', $temporalMetrics['duration_seconds']);

            // --- weighted sum
            $score =
                $this->weights['quality']       * $quality +
                ($this->weights['aesthetics'] ?? 0.0) * ($aesthetics ?? $quality) +
                $this->weights['people']        * $people  +
                ($this->weights['content'] ?? 0.0) * $contentScore +
                $this->weights['density']       * $density +
                $this->weights['novelty']       * $novelty +
                $this->weights['holiday']       * $holiday +
                $this->weights['recency']       * $recency +
                ($this->weights['location'] ?? 0.0) * $locationScore +
                ($this->weights['poi'] ?? 0.0)  * $poiScore +
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

    /** @return array{from:int,to:int}|null */
    private function computeTimeRangeFromMembers(ClusterDraft $c, array $mediaMap): ?array
    {
        $items = [];
        foreach ($c->getMembers() as $id) {
            $m = $mediaMap[$id] ?? null;
            if ($m instanceof Media) {
                $items[] = $m;
            }
        }
        if ($items === []) {
            return null;
        }
        return MediaMath::timeRangeReliable(
            $items,
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

    /**
     * @param list<Media> $mediaItems
     * @return array{quality:float,aesthetics:float|null,resolution:float|null,sharpness:float|null,iso:float|null}
     */
    private function computeQualityMetrics(array $mediaItems): array
    {
        $resolutionSum = 0.0;
        $resolutionCount = 0;
        $sharpnessSum = 0.0;
        $sharpnessCount = 0;
        $isoSum = 0.0;
        $isoCount = 0;

        $brightnessSum = 0.0;
        $brightnessCount = 0;
        $contrastSum = 0.0;
        $contrastCount = 0;
        $entropySum = 0.0;
        $entropyCount = 0;
        $colorSum = 0.0;
        $colorCount = 0;

        foreach ($mediaItems as $media) {
            $w = $media->getWidth();
            $h = $media->getHeight();
            if ($w !== null && $h !== null && $w > 0 && $h > 0) {
                $megapixels = ((float) $w * (float) $h) / 1_000_000.0;
                $resolutionSum += $this->clamp01($megapixels / \max(1e-6, $this->qualityBaselineMegapixels));
                $resolutionCount++;
            }

            $sharpness = $media->getSharpness();
            if ($sharpness !== null) {
                $sharpnessSum += $this->clamp01($sharpness);
                $sharpnessCount++;
            }

            $iso = $media->getIso();
            if ($iso !== null && $iso > 0) {
                $isoSum += $this->normalizeIso($iso);
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

        $resolution = $resolutionCount > 0 ? $resolutionSum / $resolutionCount : null;
        $sharpness = $sharpnessCount > 0 ? $sharpnessSum / $sharpnessCount : null;
        $iso = $isoCount > 0 ? $isoSum / $isoCount : null;

        $quality = $this->combineScores([
            [$resolution, 0.45],
            [$sharpness, 0.35],
            [$iso, 0.20],
        ], 0.5);

        $brightnessAvg = $brightnessCount > 0 ? $brightnessSum / $brightnessCount : null;
        $contrastAvg = $contrastCount > 0 ? $contrastSum / $contrastCount : null;
        $entropyAvg = $entropyCount > 0 ? $entropySum / $entropyCount : null;
        $colorAvg = $colorCount > 0 ? $colorSum / $colorCount : null;

        $aesthetics = $this->combineScores([
            [$brightnessAvg !== null ? $this->balancedScore($brightnessAvg, 0.55, 0.35) : null, 0.30],
            [$contrastAvg, 0.20],
            [$entropyAvg, 0.25],
            [$colorAvg, 0.25],
        ], null);

        return [
            'quality'    => $quality,
            'aesthetics' => $aesthetics,
            'resolution' => $resolution,
            'sharpness'  => $sharpness,
            'iso'        => $iso,
        ];
    }

    /**
     * @param list<Media> $mediaItems
     * @param array<string,mixed> $params
     * @return array{score:float,unique:int,mentions:int,coverage:float}
     */
    private function computePeopleMetrics(array $mediaItems, int $members, array $params): array
    {
        if (isset($params['people']) && \is_numeric($params['people'])) {
            $score = $this->clamp01((float) $params['people']);
            $mentions = (int) ($params['people_count'] ?? 0);
            $unique = (int) ($params['people_unique'] ?? 0);
            $coverage = $this->clamp01((float) ($params['people_coverage'] ?? 0.0));

            return [
                'score'    => $score,
                'unique'   => $unique,
                'mentions' => $mentions,
                'coverage' => $coverage,
            ];
        }

        $uniqueNames = [];
        $mentions = 0;
        $itemsWithPeople = 0;

        foreach ($mediaItems as $media) {
            $persons = $media->getPersons();
            if (!\is_array($persons) || $persons === []) {
                continue;
            }
            $itemsWithPeople++;
            foreach ($persons as $person) {
                if (!\is_string($person) || $person === '') {
                    continue;
                }
                $uniqueNames[$person] = true;
                $mentions++;
            }
        }

        $unique = \count($uniqueNames);
        $coverage = $members > 0 ? $itemsWithPeople / $members : 0.0;
        $richness = $unique > 0 ? \min(1.0, $unique / 4.0) : 0.0;
        $mentionScore = $members > 0 ? \min(1.0, $mentions / (float) \max(1, $members)) : 0.0;

        $score = $this->combineScores([
            [$coverage, 0.4],
            [$richness, 0.35],
            [$mentionScore, 0.25],
        ], 0.0);

        return [
            'score'    => $score,
            'unique'   => $unique,
            'mentions' => $mentions,
            'coverage' => $coverage,
        ];
    }

    /**
     * @param list<Media> $mediaItems
     * @param array<string,mixed> $params
     * @return array{score:float,unique_keywords:int,total_keywords:int,coverage:float}
     */
    private function computeContentMetrics(array $mediaItems, int $members, array $params): array
    {
        if (isset($params['content']) && \is_numeric($params['content'])) {
            $unique = (int) ($params['content_keywords_unique'] ?? 0);
            $total = (int) ($params['content_keywords_total'] ?? 0);
            $coverage = $this->clamp01((float) ($params['content_coverage'] ?? 0.0));

            return [
                'score'           => $this->clamp01((float) $params['content']),
                'unique_keywords' => $unique,
                'total_keywords'  => $total,
                'coverage'        => $coverage,
            ];
        }

        $uniqueKeywords = [];
        $totalKeywords = 0;
        $itemsWithKeywords = 0;

        foreach ($mediaItems as $media) {
            $keywords = $media->getKeywords();
            if (!\is_array($keywords) || $keywords === []) {
                continue;
            }
            $itemsWithKeywords++;
            foreach ($keywords as $keyword) {
                if (!\is_string($keyword) || $keyword === '') {
                    continue;
                }
                $uniqueKeywords[\mb_strtolower($keyword)] = true;
                $totalKeywords++;
            }
        }

        $unique = \count($uniqueKeywords);
        $coverage = $members > 0 ? $itemsWithKeywords / $members : 0.0;
        $richness = $unique > 0 ? \min(1.0, $unique / 8.0) : 0.0;
        $density = $members > 0 ? \min(1.0, $totalKeywords / (float) \max(1, $members)) : 0.0;

        $score = $this->combineScores([
            [$coverage, 0.4],
            [$richness, 0.35],
            [$density, 0.25],
        ], 0.0);

        return [
            'score'           => $score,
            'unique_keywords' => $unique,
            'total_keywords'  => $totalKeywords,
            'coverage'        => $coverage,
        ];
    }

    /**
     * @param list<Media> $mediaItems
     * @param array<string,mixed> $params
     * @return array{score:float,geo_coverage:float}
     */
    private function computeLocationMetrics(array $mediaItems, int $members, array $params): array
    {
        if (isset($params['location_score']) && \is_numeric($params['location_score'])) {
            return [
                'score'        => $this->clamp01((float) $params['location_score']),
                'geo_coverage' => $this->clamp01((float) ($params['location_geo_coverage'] ?? 0.0)),
            ];
        }

        $coords = [];
        foreach ($mediaItems as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat === null || $lon === null) {
                continue;
            }
            $coords[] = [$lat, $lon];
        }

        $withGeo = \count($coords);
        $coverage = $members > 0 ? $withGeo / $members : 0.0;
        $spread = 0.0;

        $n = \count($coords);
        if ($n > 1) {
            $centroidLat = 0.0;
            $centroidLon = 0.0;
            foreach ($coords as $coord) {
                $centroidLat += $coord[0];
                $centroidLon += $coord[1];
            }
            $centroidLat /= $n;
            $centroidLon /= $n;

            $maxDistance = 0.0;
            foreach ($coords as $coord) {
                $distance = MediaMath::haversineDistanceInMeters(
                    $centroidLat,
                    $centroidLon,
                    $coord[0],
                    $coord[1]
                );
                if ($distance > $maxDistance) {
                    $maxDistance = $distance;
                }
            }

            $spread = $maxDistance;
        }

        $compactness = $spread === 0.0 ? 1.0 : $this->clamp01(1.0 - \min(1.0, $spread / 10_000.0));

        $score = $this->combineScores([
            [$coverage, 0.7],
            [$compactness, 0.3],
        ], 0.0);

        return [
            'score'        => $score,
            'geo_coverage' => $coverage,
        ];
    }

    /**
     * @param list<Media> $mediaItems
     * @param array{from:int,to:int}|null $timeRange
     * @return array{score:float,coverage:float,duration_seconds:int}
     */
    private function computeTemporalMetrics(array $mediaItems, int $members, ?array $timeRange): array
    {
        $duration = 0;
        if (\is_array($timeRange) && isset($timeRange['from'], $timeRange['to'])) {
            $duration = \max(0, (int) $timeRange['to'] - (int) $timeRange['from']);
        }

        $timestamped = 0;
        foreach ($mediaItems as $media) {
            if ($media->getTakenAt() instanceof DateTimeImmutable) {
                $timestamped++;
            }
        }

        $coverage = $members > 0 ? $timestamped / $members : 0.0;
        $spanScore = $duration > 0 ? $this->spanScore((float) $duration) : 0.0;

        $score = $this->combineScores([
            [$coverage, 0.55],
            [$spanScore, 0.45],
        ], 0.0);

        return [
            'score'            => $score,
            'coverage'         => $coverage,
            'duration_seconds' => $duration,
        ];
    }

    /**
     * @param list<array{0:float|null,1:float}> $components
     */
    private function combineScores(array $components, ?float $default): float
    {
        $sum = 0.0;
        $weightSum = 0.0;

        foreach ($components as [$value, $weight]) {
            if ($value === null) {
                continue;
            }
            $sum += $this->clamp01($value) * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0.0) {
            return $default ?? 0.0;
        }

        return $sum / $weightSum;
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

    private function spanScore(float $durationSeconds): float
    {
        $hours = $durationSeconds / 3600.0;

        if ($hours <= 0.5) {
            return 1.0;
        }

        if ($hours >= 240.0) {
            return 0.0;
        }

        if ($hours <= 48.0) {
            return $this->clamp01(1.0 - (($hours - 0.5) / 47.5) * 0.4);
        }

        return $this->clamp01(0.6 - (($hours - 48.0) / 192.0) * 0.6);
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
        $metrics = $this->computeQualityMetrics($this->collectMediaItems($c, $mediaMap));

        return $metrics['quality'];
    }
}
