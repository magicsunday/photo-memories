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
        /** @var array{quality:float,people:float,density:float,novelty:float,holiday:float,recency:float} */
        private readonly array $weights = [
            'quality' => 0.25,
            'people'  => 0.20,
            'density' => 0.15,
            'novelty' => 0.10,
            'holiday' => 0.10,
            'recency' => 0.20,
        ],
        /** @var array<string,float> $algorithmBoosts */
        private readonly array $algorithmBoosts = [],
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
            $params = $c->getParams();

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
            $quality = (float) ($params['quality_avg'] ?? $this->computeQualityAvg($c, $mediaMap));
            $c->setParam('quality_avg', $quality);

            // --- people
            $peopleCountRaw = (float) ($params['people_count'] ?? 0.0);
            $people = $peopleCountRaw > 0.0 ? \min(1.0, $peopleCountRaw / 5.0) : 0.0;
            $c->setParam('people', $people);

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
                $ageDays = \max(0.0, ($now - (int) $tr['to']) / 86400.0);
                $recency = \max(0.0, 1.0 - \min(1.0, $ageDays / 365.0));
            }
            $c->setParam('recency', $recency);

            // --- weighted sum
            $score =
                $this->weights['quality'] * $quality +
                $this->weights['people']  * $people  +
                $this->weights['density'] * $density +
                $this->weights['novelty'] * $novelty +
                $this->weights['holiday'] * $holiday +
                $this->weights['recency'] * $recency;

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
