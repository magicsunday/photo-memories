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
        /** @var array{quality:float,people:float,content:float,density:float,novelty:float,holiday:float,recency:float} */
        private readonly array $weights = [
            'quality' => 0.22,
            'people'  => 0.20,
            'content' => 0.13,
            'density' => 0.12,
            'novelty' => 0.12,
            'holiday' => 0.08,
            'recency' => 0.13,
        ],
        /** @var array<string,float> $algorithmBoosts */
        private readonly array $algorithmBoosts = [],
        private readonly float $qualityBaselineMegapixels = 12.0,
        private readonly float $qualitySharpnessWeight = 0.3,
        private readonly float $qualityAestheticWeight = 0.2,
        private readonly int $recencyAnniversaryWindowDays = 6,
        private readonly float $recencyAnniversaryBoostStrength = 0.65,
        private readonly float $diversityDayPenaltyWeight = 0.0,
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

        $qualityWeights = $this->resolveQualityWeights();
        $mediaQuality   = $this->precomputeMediaQuality($mediaMap, $qualityWeights);

        $timeRanges = [];
        $dayKeys    = [];
        foreach ($clusters as $idx => $cluster) {
            $timeRange = $this->resolveTimeRange($cluster, $mediaMap);
            $timeRanges[$idx] = $timeRange;
            if ($timeRange !== null) {
                $dayKeys[$idx] = \gmdate('Y-m-d', (int) $timeRange['from']);
            }
        }

        $dayCounts = $this->buildDayBucketCounts($dayKeys);

        foreach ($clusters as $idx => $c) {
            $params = $c->getParams();

            /** @var array{from:int,to:int}|null $tr */
            $tr = $timeRanges[$idx] ?? null;

            // --- quality_avg
            $qualityStats = $this->computeQualityStats($c, $mediaQuality);
            $quality      = (float) ($params['quality_avg'] ?? $qualityStats['score']);
            $c->setParam('quality_avg', $quality);

            if ($qualityStats['resolution'] !== null) {
                $c->setParam('quality_resolution_avg', $qualityStats['resolution']);
            }
            $c->setParam('quality_resolution_coverage', $qualityStats['resolution_coverage']);

            if ($qualityStats['sharpness'] !== null) {
                $c->setParam('quality_sharpness_avg', $qualityStats['sharpness']);
            }
            $c->setParam('quality_sharpness_coverage', $qualityStats['sharpness_coverage']);

            if ($qualityStats['aesthetic'] !== null) {
                $c->setParam('quality_aesthetic_avg', $qualityStats['aesthetic']);
            }
            $c->setParam('quality_aesthetic_coverage', $qualityStats['aesthetic_coverage']);

            if ($qualityStats['best_media_id'] !== null) {
                $c->setParam('quality_best_media_id', $qualityStats['best_media_id']);
                $c->setParam('quality_best_media_score', $qualityStats['best_media_score']);
            }

            // --- people stats derived from metadata
            $peopleStats = $this->computePeopleStats($c, $mediaMap);
            $c->setParam('people_media_with_faces', $peopleStats['media_with_people']);
            $c->setParam('people_faces_total', $peopleStats['faces_total']);
            $c->setParam('people_faces_avg', $peopleStats['faces_avg']);
            $c->setParam('people_coverage', $peopleStats['coverage']);
            $c->setParam('people_unique_count', $peopleStats['unique']);
            if ($peopleStats['primary'] !== null) {
                $c->setParam('people_primary', $peopleStats['primary']);
            }
            if ($peopleStats['primary_id'] !== null) {
                $c->setParam('people_primary_id', $peopleStats['primary_id']);
            }
            if ($peopleStats['primary_share'] !== null) {
                $c->setParam('people_primary_share', $peopleStats['primary_share']);
            }
            if ($peopleStats['primary_faces_share'] !== null) {
                $c->setParam('people_primary_faces_share', $peopleStats['primary_faces_share']);
            }
            if ($peopleStats['primary_confidence'] !== null) {
                $c->setParam('people_primary_confidence', $peopleStats['primary_confidence']);
            }

            // --- people
            $peopleCountRaw = (float) ($params['people_count'] ?? 0.0);
            $people = 0.0;
            if ($peopleCountRaw > 0.0) {
                $people = \min(1.0, $peopleCountRaw / 5.0);
            } elseif ($peopleStats['score'] !== null) {
                $people = $peopleStats['score'];
            }
            $c->setParam('people', $people);

            // --- content & keywords
            $contentStats = $this->computeContentStats($c, $mediaMap);
            $c->setParam('content_keywords_media', $contentStats['media_with_keywords']);
            $c->setParam('content_keywords_total', $contentStats['keywords_total']);
            $c->setParam('content_keywords_unique', $contentStats['keywords_unique']);

            if ($contentStats['top_keyword'] !== null) {
                $c->setParam('content_keywords_top', $contentStats['top_keyword']);
            }
            if ($contentStats['top_keyword_share'] !== null) {
                $c->setParam('content_keywords_top_share', $contentStats['top_keyword_share']);
            }
            if ($contentStats['top_keyword_media_share'] !== null) {
                $c->setParam('content_keywords_top_media_share', $contentStats['top_keyword_media_share']);
            }
            $c->setParam('content', $contentStats['score']);

            // --- density (only with valid time)
            $density = 0.0;
            if ($tr !== null) {
                $duration = \max(1, (int) $tr['to'] - (int) $tr['from']);
                $n        = \max(1, \count($c->getMembers()));
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
                $recencyData = $this->computeRecencyComponents((int) $tr['from'], (int) $tr['to'], $now);
                $recency     = $recencyData['score'];

                $c->setParam('recency_base', $recencyData['base']);
                if ($recencyData['anniversary_affinity'] > 0.0) {
                    $c->setParam('recency_anniversary_affinity', $recencyData['anniversary_affinity']);
                }
                if ($recencyData['anniversary_boost'] > 0.0) {
                    $c->setParam('recency_anniversary_boost', $recencyData['anniversary_boost']);
                }
            }
            $c->setParam('recency', $recency);

            // --- weighted sum
            $score =
                $this->weights['quality'] * $quality +
                $this->weights['people']  * $people  +
                $this->weights['content'] * $contentStats['score'] +
                $this->weights['density'] * $density +
                $this->weights['novelty'] * $novelty +
                $this->weights['holiday'] * $holiday +
                $this->weights['recency'] * $recency;

            if ($this->diversityDayPenaltyWeight > 0.0 && $tr !== null) {
                $dayKey = $dayKeys[$idx] ?? null;
                if ($dayKey !== null) {
                    $siblings = $dayCounts[$dayKey] ?? 1;
                    if ($siblings > 1) {
                        $penalty = 1.0 / (1.0 + $this->diversityDayPenaltyWeight * (float) ($siblings - 1));
                        $score  *= $penalty;
                        $c->setParam('score_diversity_penalty', $penalty);
                        $c->setParam('score_diversity_siblings', $siblings);
                    }
                }
            }

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
     * @param array<int,Media> $mediaMap
     * @return array{
     *     score: float,
     *     media_with_keywords: int,
     *     keywords_total: int,
     *     keywords_unique: int,
     *     top_keyword: string|null,
     *     top_keyword_share: float|null,
     *     top_keyword_media_share: float|null,
     * }
     */
    private function computeContentStats(ClusterDraft $c, array $mediaMap): array
    {
        $memberCount = \count($c->getMembers());

        $keywordCounts = [];
        $keywordMediaCounts = [];
        $keywordLabels = [];
        $totalKeywords = 0;
        $mediaWithKeywords = 0;

        foreach ($c->getMembers() as $id) {
            $media = $mediaMap[$id] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            $keywordsRaw = $media->getKeywords();
            if (!\is_array($keywordsRaw) || $keywordsRaw === []) {
                continue;
            }

            $seenThisMedia = [];
            $hadKeyword = false;

            foreach ($keywordsRaw as $entry) {
                if (!\is_string($entry)) {
                    continue;
                }

                $normalized = $this->normalizeKeyword($entry);
                if ($normalized === null) {
                    continue;
                }

                $totalKeywords++;
                $hadKeyword = true;

                if (!isset($keywordCounts[$normalized])) {
                    $keywordCounts[$normalized] = 0;
                    $keywordMediaCounts[$normalized] = 0;
                    $keywordLabels[$normalized] = \trim($entry);
                }

                $keywordCounts[$normalized]++;

                if (!isset($seenThisMedia[$normalized])) {
                    $keywordMediaCounts[$normalized]++;
                    $seenThisMedia[$normalized] = true;
                }
            }

            if ($hadKeyword) {
                $mediaWithKeywords++;
            }
        }

        $uniqueKeywords = \count($keywordCounts);
        $topKeyword = null;
        $topKeywordShare = null;
        $topKeywordMediaShare = null;

        if ($totalKeywords > 0 && $keywordCounts !== []) {
            \arsort($keywordCounts);
            $topNormalized = \array_key_first($keywordCounts);
            if (\is_string($topNormalized)) {
                $topKeyword = $keywordLabels[$topNormalized] ?? $topNormalized;
                $topCount = $keywordCounts[$topNormalized];
                $topKeywordShare = $topCount / (float) $totalKeywords;

                $topMediaCount = $keywordMediaCounts[$topNormalized] ?? 0;
                if ($memberCount > 0 && $topMediaCount > 0) {
                    $topKeywordMediaShare = $topMediaCount / (float) $memberCount;
                }
            }
        }

        $score = 0.0;
        if ($totalKeywords > 0) {
            $shareScore = $topKeywordShare !== null ? $this->clamp01($topKeywordShare) : 0.0;
            $mediaCoverage = $topKeywordMediaShare !== null ? $this->clamp01($topKeywordMediaShare) : 0.0;
            $diversity = $uniqueKeywords > 0 ? $this->clamp01($uniqueKeywords / 6.0) : 0.0;

            $score = 0.5 * $shareScore + 0.3 * $mediaCoverage + 0.2 * $diversity;
            $score = $this->clamp01($score);
        }

        return [
            'score' => $score,
            'media_with_keywords' => $mediaWithKeywords,
            'keywords_total' => $totalKeywords,
            'keywords_unique' => $uniqueKeywords,
            'top_keyword' => $topKeyword,
            'top_keyword_share' => $topKeywordShare,
            'top_keyword_media_share' => $topKeywordMediaShare,
        ];
    }

    private function normalizeKeyword(string $entry): ?string
    {
        $normalized = \trim($entry);
        if ($normalized === '') {
            return null;
        }

        if (\function_exists('mb_strtolower')) {
            return \mb_strtolower($normalized);
        }

        return \strtolower($normalized);
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

    /**
     * @return array{from:int,to:int}|null
     */
    private function resolveTimeRange(ClusterDraft $c, array $mediaMap): ?array
    {
        $params = $c->getParams();
        /** @var array{from:int,to:int}|null $tr */
        $tr = (\is_array($params['time_range'] ?? null)) ? $params['time_range'] : null;
        if ($this->isValidTimeRange($tr)) {
            return $tr;
        }

        $re = $this->computeTimeRangeFromMembers($c, $mediaMap);
        if ($re !== null) {
            $c->setParam('time_range', $re);
        }

        return $re;
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

    /**
     * @param array<int,string> $dayKeys
     * @return array<string,int>
     */
    private function buildDayBucketCounts(array $dayKeys): array
    {
        $counts = [];
        foreach ($dayKeys as $day) {
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        return $counts;
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

    /**
     * @return array{
     *     score: float|null,
     *     media_with_people: int,
     *     faces_total: int,
     *     faces_avg: float,
     *     coverage: float,
     *     unique: int,
     *     primary: string|null,
     *     primary_id: string|null,
     *     primary_share: float|null,
     *     primary_faces_share: float|null,
     *     primary_confidence: float|null,
     * }
     */
    private function computePeopleStats(ClusterDraft $c, array $mediaMap): array
    {
        $members = $c->getMembers();
        $memberCount = \count($members);

        $mediaWithPeople = 0;
        $personCounts = [];
        $facesTotal = 0;

        foreach ($members as $id) {
            $media = $mediaMap[$id] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            $persons = $media->getPersons();
            if (!\is_array($persons) || $persons === []) {
                continue;
            }

            $mediaWithPeople++;

            $seenInMedia = [];
            $facesInMedia = 0;
            foreach ($persons as $person) {
                $normalized = $this->normalizePersonEntry($person);
                if ($normalized === null) {
                    continue;
                }

                $facesInMedia++;
                $facesTotal++;

                $key = $normalized['key'];
                if (!isset($personCounts[$key])) {
                    $personCounts[$key] = [
                        'label' => $normalized['label'],
                        'identifier' => $normalized['identifier'],
                        'media' => 0,
                        'occurrences' => 0,
                        'confidence_sum' => 0.0,
                        'confidence_count' => 0,
                    ];
                }

                $personCounts[$key]['occurrences']++;

                if ($normalized['confidence'] !== null) {
                    $personCounts[$key]['confidence_sum'] += $normalized['confidence'];
                    $personCounts[$key]['confidence_count']++;
                }

                if (!isset($seenInMedia[$key])) {
                    $personCounts[$key]['media']++;
                    $seenInMedia[$key] = true;
                }
            }

            if ($facesInMedia === 0) {
                // reset the increment because we did not recognise any usable faces
                $mediaWithPeople--;
            }
        }

        $uniquePeople = \count($personCounts);
        $coverage = $memberCount > 0 ? $mediaWithPeople / \max(1, $memberCount) : 0.0;
        if ($coverage > 1.0) {
            $coverage = 1.0;
        }

        $facesAvg = $mediaWithPeople > 0 ? $facesTotal / (float) $mediaWithPeople : 0.0;

        $primaryName = null;
        $primaryId = null;
        $primaryMediaCount = 0;
        $primaryFaceCount = 0;
        $primaryConfidence = null;

        foreach ($personCounts as $data) {
            if ($data['occurrences'] > $primaryFaceCount) {
                $primaryFaceCount = (int) $data['occurrences'];
                $primaryMediaCount = (int) $data['media'];
                $primaryName = $data['label'];
                $primaryId = $data['identifier'];

                if ($data['confidence_count'] > 0) {
                    $primaryConfidence = $data['confidence_sum'] / $data['confidence_count'];
                } else {
                    $primaryConfidence = null;
                }
            }
        }

        $primaryShare = null;
        if ($primaryMediaCount > 0 && $mediaWithPeople > 0) {
            $primaryShare = (float) $primaryMediaCount / (float) $mediaWithPeople;
        }

        $primaryFacesShare = null;
        if ($primaryFaceCount > 0 && $facesTotal > 0) {
            $primaryFacesShare = (float) $primaryFaceCount / (float) $facesTotal;
        }

        $score = null;
        if ($mediaWithPeople > 0) {
            $coverageScore = $this->clamp01((float) $coverage);
            $uniqueScore = $uniquePeople > 0 ? $this->clamp01($uniquePeople / 4.0) : 0.0;
            $facesScore = $facesAvg > 0.0 ? $this->clamp01($facesAvg / 3.0) : 0.0;

            $score = 0.5 * $coverageScore + 0.3 * $uniqueScore + 0.2 * $facesScore;

            if ($primaryFacesShare !== null && $primaryFacesShare >= 0.6) {
                $score += 0.05;
            }
            if ($primaryShare !== null && $primaryShare >= 0.7) {
                $score += 0.05;
            }
            $score = $this->clamp01($score);
        }

        return [
            'score' => $score,
            'media_with_people' => $mediaWithPeople,
            'faces_total' => $facesTotal,
            'faces_avg' => $facesAvg,
            'coverage' => $memberCount > 0 ? $coverage : 0.0,
            'unique' => $uniquePeople,
            'primary' => $primaryName,
            'primary_id' => $primaryId,
            'primary_share' => $primaryShare,
            'primary_faces_share' => $primaryFacesShare,
            'primary_confidence' => $primaryConfidence,
        ];
    }

    /**
     * @param mixed $entry
     * @return array{key:string,label:string,identifier:string|null,confidence:float|null}|null
     */
    private function normalizePersonEntry(mixed $entry): ?array
    {
        if (\is_string($entry)) {
            $label = \trim($entry);
            if ($label === '') {
                return null;
            }

            return [
                'key' => $label,
                'label' => $label,
                'identifier' => null,
                'confidence' => null,
            ];
        }

        if (!\is_array($entry)) {
            return null;
        }

        $identifier = null;
        if (\is_string($entry['id'] ?? null)) {
            $identifier = \trim((string) $entry['id']);
            if ($identifier === '') {
                $identifier = null;
            }
        }

        $label = null;
        foreach (['name', 'label', 'displayName'] as $key) {
            if (\is_string($entry[$key] ?? null)) {
                $candidate = \trim((string) $entry[$key]);
                if ($candidate !== '') {
                    $label = $candidate;
                    break;
                }
            }
        }

        if ($label === null) {
            $label = $identifier;
        }

        if ($label === null) {
            return null;
        }

        $confidence = null;
        foreach (['confidence', 'score', 'probability'] as $key) {
            if (isset($entry[$key]) && \is_numeric($entry[$key])) {
                $confidence = (float) $entry[$key];
                break;
            }
        }

        $key = $identifier ?? $label;

        return [
            'key' => $key,
            'label' => $label,
            'identifier' => $identifier,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param array<int,array{score:float,resolution:float,sharpness:float,aesthetic:float,has_sharpness:bool,has_aesthetic:bool}> $mediaQuality
     * @return array{score:float,resolution:float|null,resolution_coverage:float,sharpness:float|null,sharpness_coverage:float,aesthetic:float|null,aesthetic_coverage:float,best_media_id:int|null,best_media_score:float|null}
     */
    private function computeQualityStats(ClusterDraft $c, array $mediaQuality): array
    {
        $scoreSum = 0.0;
        $scoreCount = 0;

        $resolutionSum = 0.0;
        $resolutionCount = 0;

        $sharpnessSum = 0.0;
        $sharpnessCount = 0;

        $aestheticSum = 0.0;
        $aestheticCount = 0;

        $memberCount = \count($c->getMembers());

        $bestMediaId = null;
        $bestMediaScore = null;

        foreach ($c->getMembers() as $id) {
            $entry = $mediaQuality[$id] ?? null;
            if ($entry === null) {
                continue;
            }

            $scoreSum += $entry['score'];
            $scoreCount++;

            if ($bestMediaScore === null || $entry['score'] > $bestMediaScore) {
                $bestMediaId = $id;
                $bestMediaScore = $entry['score'];
            }

            $resolutionSum += $entry['resolution'];
            $resolutionCount++;

            if ($entry['has_sharpness']) {
                $sharpnessSum += $entry['sharpness'];
                $sharpnessCount++;
            }

            if ($entry['has_aesthetic']) {
                $aestheticSum += $entry['aesthetic'];
                $aestheticCount++;
            }
        }

        $score = $scoreCount > 0 ? $scoreSum / $scoreCount : 0.5;
        $resolutionAvg = $resolutionCount > 0 ? $resolutionSum / $resolutionCount : null;
        $sharpnessAvg = $sharpnessCount > 0 ? $sharpnessSum / $sharpnessCount : null;
        $aestheticAvg = $aestheticCount > 0 ? $aestheticSum / $aestheticCount : null;

        $denom = $memberCount > 0 ? (float) $memberCount : 1.0;

        return [
            'score' => $score,
            'resolution' => $resolutionAvg,
            'resolution_coverage' => $memberCount > 0 ? $resolutionCount / $denom : 0.0,
            'sharpness' => $sharpnessAvg,
            'sharpness_coverage' => $sharpnessCount / $denom,
            'aesthetic' => $aestheticAvg,
            'aesthetic_coverage' => $aestheticCount / $denom,
            'best_media_id' => $bestMediaId,
            'best_media_score' => $bestMediaScore,
        ];
    }

    /**
     * @return array{score:float,base:float,anniversary_affinity:float,anniversary_boost:float}
     */
    private function computeRecencyComponents(int $fromTs, int $toTs, int $nowTs): array
    {
        if ($toTs < $fromTs) {
            [$fromTs, $toTs] = [$toTs, $fromTs];
        }

        $ageDays = \max(0.0, ($nowTs - $toTs) / 86400.0);
        $base    = \max(0.0, 1.0 - \min(1.0, $ageDays / 365.0));

        $affinity = $this->computeAnniversaryAffinity($toTs, $nowTs);
        $boost    = 0.0;

        if ($affinity > 0.0 && $base < 1.0) {
            $boostStrength = $this->clamp01($this->recencyAnniversaryBoostStrength);
            if ($boostStrength > 0.0) {
                $boost = (1.0 - $base) * $boostStrength * $affinity;
            }
        }

        $score = $this->clamp01($base + $boost);

        return [
            'score' => $score,
            'base' => $base,
            'anniversary_affinity' => $affinity,
            'anniversary_boost' => $boost,
        ];
    }

    /**
     * @return array{resolution:float,sharpness:float,aesthetic:float}
     */
    private function resolveQualityWeights(): array
    {
        $sharp = $this->clamp01($this->qualitySharpnessWeight);
        $aesthetic = $this->clamp01($this->qualityAestheticWeight);

        $sum = $sharp + $aesthetic;
        if ($sum > 1.0) {
            $factor = 1.0 / $sum;
            $sharp *= $factor;
            $aesthetic *= $factor;
        }

        $resolution = \max(0.0, 1.0 - ($sharp + $aesthetic));

        return [
            'resolution' => $resolution,
            'sharpness' => $sharp,
            'aesthetic' => $aesthetic,
        ];
    }

    /**
     * @param array<int,Media> $mediaMap
     * @param array{resolution:float,sharpness:float,aesthetic:float} $weights
     * @return array<int,array{score:float,resolution:float,sharpness:float,aesthetic:float,has_sharpness:bool,has_aesthetic:bool}>
     */
    private function precomputeMediaQuality(array $mediaMap, array $weights): array
    {
        $cache = [];

        foreach ($mediaMap as $id => $media) {
            $quality = $this->computeMediaQuality($media, $weights);
            if ($quality !== null) {
                $cache[$id] = $quality;
            }
        }

        return $cache;
    }

    /**
     * @param array{resolution:float,sharpness:float,aesthetic:float} $weights
     * @return array{score:float,resolution:float,sharpness:float,aesthetic:float,has_sharpness:bool,has_aesthetic:bool}|null
     */
    private function computeMediaQuality(Media $m, array $weights): ?array
    {
        $w = $m->getWidth();
        $h = $m->getHeight();
        if ($w === null || $h === null || $w <= 0 || $h <= 0) {
            return null;
        }

        $mp      = ((float) $w * (float) $h) / 1_000_000.0;
        $resNorm = $this->clamp01($mp / \max(1e-6, $this->qualityBaselineMegapixels));

        $score = $weights['resolution'] * $resNorm;

        $sharp = $m->getSharpness();
        $hasSharpness = $sharp !== null;
        $sharpNorm = $hasSharpness
            ? $this->clamp01((float) $sharp)
            : 0.5; // neutral fallback if sharpness unknown

        $score += $weights['sharpness'] * $sharpNorm;

        $aesthetic = $this->computeAestheticQuality($m);
        $hasAesthetic = $aesthetic !== null;
        $aestheticNorm = $hasAesthetic ? (float) $aesthetic : 0.5;

        $score += $weights['aesthetic'] * $aestheticNorm;

        return [
            'score' => $score,
            'resolution' => $resNorm,
            'sharpness' => $sharpNorm,
            'aesthetic' => $aestheticNorm,
            'has_sharpness' => $hasSharpness,
            'has_aesthetic' => $hasAesthetic,
        ];
    }

    private function computeAestheticQuality(Media $m): ?float
    {
        $scores = [];

        $brightness = $m->getBrightness();
        if ($brightness !== null) {
            $scores[] = $this->midpointPreference($brightness, 0.55, 0.35);
        }

        $contrast = $m->getContrast();
        if ($contrast !== null) {
            $scores[] = $this->smoothStep($contrast, 0.12, 0.55);
        }

        $entropy = $m->getEntropy();
        if ($entropy !== null) {
            $scores[] = $this->smoothStep($entropy, 0.18, 0.75);
        }

        $colorfulness = $m->getColorfulness();
        if ($colorfulness !== null) {
            $scores[] = $this->smoothStep($colorfulness, 0.15, 0.70);
        }

        if ($scores === []) {
            return null;
        }

        $sum = \array_sum($scores);
        $avg = $sum / \count($scores);

        return \max(0.0, \min(1.0, $avg));
    }

    private function computeAnniversaryAffinity(int $eventTs, int $nowTs): float
    {
        $window = $this->recencyAnniversaryWindowDays;
        if ($window <= 0) {
            return 0.0;
        }

        $eventDate = (new \DateTimeImmutable('@' . $eventTs))->setTime(0, 0);
        $nowDate   = (new \DateTimeImmutable('@' . $nowTs))->setTime(0, 0);

        $month = (int) $eventDate->format('n');
        $day   = (int) $eventDate->format('j');
        $year  = (int) $nowDate->format('Y');

        $daysInMonth = (int) (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month)))->format('t');
        if ($day > $daysInMonth) {
            $day = $daysInMonth;
        }

        $anniversary = \DateTimeImmutable::createFromFormat('!Y-m-d', \sprintf('%04d-%02d-%02d', $year, $month, $day));
        if (!$anniversary instanceof \DateTimeImmutable) {
            return 0.0;
        }

        $candidates = [
            $anniversary,
            $anniversary->modify('-1 year'),
            $anniversary->modify('+1 year'),
        ];

        $minDelta = null;
        foreach ($candidates as $candidate) {
            $diff = $nowDate->diff($candidate);
            $deltaDays = (float) ($diff->days ?? 0);
            if ($minDelta === null || $deltaDays < $minDelta) {
                $minDelta = $deltaDays;
            }
        }

        if ($minDelta === null || $minDelta > $window) {
            return 0.0;
        }

        $t = 1.0 - ($minDelta / $window);
        return $this->smoothStep($t, 0.0, 1.0);
    }

    private function midpointPreference(float $value, float $midpoint, float $tolerance): float
    {
        $value = $this->clamp01($value);
        $delta = \abs($value - $midpoint);
        if ($delta >= $tolerance) {
            return 0.0;
        }

        $x = 1.0 - $delta / $tolerance;
        return $x * $x * (3.0 - 2.0 * $x);
    }

    private function smoothStep(float $value, float $min, float $max): float
    {
        $value = $this->clamp01($value);
        if ($value <= $min) {
            return 0.0;
        }
        if ($value >= $max) {
            return 1.0;
        }

        $t = ($value - $min) / \max(1e-6, $max - $min);
        return $t * $t * (3.0 - 2.0 * $t);
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
}
