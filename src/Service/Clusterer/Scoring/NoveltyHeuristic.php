<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function floor;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function round;
use function strtolower;
use function substr;

use const SORT_NUMERIC;

/**
 * Computes a novelty score (0..1) per cluster using corpus-level rarity signals.
 * Signals:
 *  - staypoint rarity: primary staypoint popularity across the corpus
 *  - rare staypoints : share of members attached to seldom visited staypoints
 *  - time rarity     : day-of-year frequency
 *  - device rarity   : camera model frequency
 *  - content rarity  : perceptual hash prefix frequency
 *  - history novelty : overlap with recent feed output
 *
 * No external models; runs on fields already present on Media.
 */
final class NoveltyHeuristic extends AbstractClusterScoreHeuristic
{
    /** @var array<string,mixed>|null */
    private ?array $stats = null;

    public function __construct(
        private float $gridStepDeg = 0.3,
        private int $phashPrefixNibbles = 5,
        private bool $applyHistoryPenalty = true,
        private int $rareStaypointThreshold = 6,
        private int $historyWindowHours = 6,
        /**
         * @var array{
         *     staypoint: float,
         *     rare_staypoint: float,
         *     time: float,
         *     device: float,
         *     content: float,
         *     history: float
         * }
         */
        private array $weights = [
            'staypoint'      => 0.30,
            'rare_staypoint' => 0.10,
            'time'           => 0.20,
            'device'         => 0.20,
            'content'        => 0.10,
            'history'        => 0.10,
        ],
    ) {
        if ($this->phashPrefixNibbles < 1) {
            $this->phashPrefixNibbles = 1;
        }

        if ($this->historyWindowHours < 1) {
            $this->historyWindowHours = 1;
        }

        if ($this->rareStaypointThreshold < 0) {
            $this->rareStaypointThreshold = 0;
        }
    }

    public function prepare(array $clusters, array $mediaMap): void
    {
        $this->stats = $this->buildCorpusStats($mediaMap, $clusters);
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params  = $cluster->getParams();
        $novelty = $this->floatOrNull($params['novelty'] ?? null);
        if ($novelty === null) {
            $stats       = $this->stats ?? $this->buildCorpusStats($mediaMap, [$cluster]);
            $novelty     = $this->computeNovelty($cluster, $mediaMap, $stats);
            $this->stats = $stats;
        }

        $cluster->setParam('novelty', $novelty);
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['novelty'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'novelty';
    }

    /**
     * Precompute corpus histograms from the media universe you consider (ideally all indexed media).
     *
     * @param array<int, Media> $mediaMap id => Media
     * @param list<ClusterDraft> $clusters
     *
     * @return array{
     *   total:int,
     *   device: array<string,int>,
     *   grid:   array<string,int>,
     *   staypoint: array<string,int>,
     *   doy:    array<int,int>,
     *   phash:  array<string,int>,
     *   rareStaypointThreshold:int,
     *   max:    array{device:int,grid:int,staypoint:int,doy:int,phash:int}
     * }
     */
    public function buildCorpusStats(array $mediaMap, array $clusters = []): array
    {
        $device     = [];
        $grid       = [];
        $staypoints = [];
        $doy        = [];
        $phash      = [];

        $total = 0;
        foreach ($mediaMap as $m) {
            ++$total;

            $dev = $m->getCameraModel();
            if (is_string($dev) && $dev !== '') {
                $device[$dev] = ($device[$dev] ?? 0) + 1;
            }

            $lat = $m->getGpsLat();
            $lon = $m->getGpsLon();
            if ($lat !== null && $lon !== null) {
                $cell        = $this->gridCell($lat, $lon);
                $grid[$cell] = ($grid[$cell] ?? 0) + 1;
            }

            $t = $m->getTakenAt();
            if ($t instanceof DateTimeImmutable) {
                $d       = (int) $t->format('z') + 1;
                $doy[$d] = ($doy[$d] ?? 0) + 1;
            }

            $prefix = $this->phashPrefix($m->getPhash());
            if ($prefix !== null) {
                $phash[$prefix] = ($phash[$prefix] ?? 0) + 1;
            }
        }

        foreach ($clusters as $cluster) {
            $params = $cluster->getParams();
            $meta   = $params['staypoints'] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $counts = $this->sanitizeStringIntMap($meta['counts'] ?? null);
            foreach ($counts as $key => $count) {
                $staypoints[$key] = ($staypoints[$key] ?? 0) + $count;
            }
        }

        $max = [
            'device'     => $this->maxVal($device),
            'grid'       => $this->maxVal($grid),
            'staypoint'  => $this->maxVal($staypoints),
            'doy'        => $this->maxVal($doy),
            'phash'      => $this->maxVal($phash),
        ];

        return [
            'total'                  => $total,
            'device'                 => $device,
            'grid'                   => $grid,
            'staypoint'              => $staypoints,
            'doy'                    => $doy,
            'phash'                  => $phash,
            'rareStaypointThreshold' => $this->rareStaypointThreshold,
            'max'                    => $max,
        ];
    }

    /**
     * Compute novelty for a cluster using precomputed corpus stats.
     *
     * @param ClusterDraft         $cluster
     * @param array<int, Media>    $mediaMap id => Media
     * @param array<string, mixed> $stats    see buildCorpusStats()
     *
     * @return float
     *
     * @throws DateMalformedStringException
     */
    public function computeNovelty(ClusterDraft $cluster, array $mediaMap, array $stats): float
    {
        $staypointScore      = $this->scoreStaypointRarity($cluster, $stats);
        $rareStaypointScore  = $this->scoreRareStaypoints($cluster, $stats);
        $timeScore           = $this->scoreTimeRarity($cluster, $mediaMap, $stats);
        $deviceScore         = $this->scoreDeviceRarity($cluster, $mediaMap, $stats);
        $contentScore        = $this->scoreContentRarity($cluster, $mediaMap, $stats);
        $historyScore        = $this->computeHistoryScore($cluster, $mediaMap, $stats);

        return
            $this->weights['staypoint']      * $staypointScore +
            $this->weights['rare_staypoint'] * $rareStaypointScore +
            $this->weights['time']           * $timeScore +
            $this->weights['device']         * $deviceScore +
            $this->weights['content']        * $contentScore +
            $this->weights['history']        * $historyScore;
    }

    private function scoreStaypointRarity(ClusterDraft $cluster, array $stats): float
    {
        $keys = $this->staypointKeys($cluster);
        if ($keys !== []) {
            $counts = [];
            foreach ($keys as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }

            $bestKey = null;
            $best    = 0;
            foreach ($counts as $key => $count) {
                if ($count > $best) {
                    $best    = $count;
                    $bestKey = $key;
                }
            }

            if ($bestKey !== null) {
                $globalCount = (int) ($stats['staypoint'][$bestKey] ?? 0);
                $maxCount    = (int) ($stats['max']['staypoint'] ?? 0);

                return $this->rarityFromCounts($globalCount, $maxCount);
            }
        }

        $centroid = $cluster->getCentroid();
        if (isset($centroid['lat'], $centroid['lon']) && is_float($centroid['lat']) && is_float($centroid['lon'])) {
            $cell  = $this->gridCell($centroid['lat'], $centroid['lon']);
            $cnt   = (int) ($stats['grid'][$cell] ?? 0);
            $max   = (int) ($stats['max']['grid'] ?? 0);

            return $this->rarityFromCounts($cnt, $max);
        }

        return 0.5;
    }

    private function scoreRareStaypoints(ClusterDraft $cluster, array $stats): float
    {
        $keys      = array_values($this->staypointKeys($cluster));
        $threshold = (int) ($stats['rareStaypointThreshold'] ?? $this->rareStaypointThreshold);
        if ($keys === []) {
            return 0.5;
        }

        if ($threshold <= 0) {
            return 0.5;
        }

        $rare = 0;
        foreach ($keys as $key) {
            $count = (int) ($stats['staypoint'][$key] ?? 0);
            if ($count === 0 || $count <= $threshold) {
                ++$rare;
            }
        }

        return $rare / count($keys);
    }

    /**
     * @throws DateMalformedStringException
     */
    private function scoreTimeRarity(ClusterDraft $cluster, array $mediaMap, array $stats): float
    {
        $days = $this->collectClusterDays($cluster, $mediaMap);
        if ($days === []) {
            return 0.5;
        }

        $acc = 0.0;
        foreach ($days as $d) {
            $cnt = (int) ($stats['doy'][$d] ?? 0);
            $max = (int) ($stats['max']['doy'] ?? 0);
            $acc += $this->rarityFromCounts($cnt, $max);
        }

        return $acc / count($days);
    }

    private function scoreDeviceRarity(ClusterDraft $cluster, array $mediaMap, array $stats): float
    {
        $majorDev = $this->majorityDevice($cluster, $mediaMap);
        if ($majorDev === null) {
            return 0.5;
        }

        $cnt = (int) ($stats['device'][$majorDev] ?? 0);
        $max = (int) ($stats['max']['device'] ?? 0);

        return $this->rarityFromCounts($cnt, $max);
    }

    private function scoreContentRarity(ClusterDraft $cluster, array $mediaMap, array $stats): float
    {
        $prefix = $this->majorityPhashPrefix($cluster, $mediaMap);
        if ($prefix === null) {
            return 0.5;
        }

        $cnt = (int) ($stats['phash']['h:' . $prefix] ?? 0);
        $max = (int) ($stats['max']['phash'] ?? 0);

        return $this->rarityFromCounts($cnt, $max);
    }

    private function computeHistoryScore(ClusterDraft $cluster, array $mediaMap, array $stats): float
    {
        if (!$this->applyHistoryPenalty) {
            return 0.5;
        }

        $params  = $cluster->getParams();
        $history = $params['novelty_history'] ?? $params['feed_history'] ?? null;
        if (!is_array($history)) {
            return 0.5;
        }

        $components = [];

        $phashHistory = $this->sanitizeStringIntMap($history['phash_prefixes'] ?? null);
        if ($phashHistory !== []) {
            $prefix = $this->majorityPhashPrefix($cluster, $mediaMap);
            if ($prefix !== null) {
                $cnt = (int) ($phashHistory[$prefix] ?? 0);
                $max = $this->maxVal($phashHistory);
                if ($max <= 0) {
                    $max = max(1, count($phashHistory));
                }

                $components[] = $this->rarityFromCounts($cnt, $max);
            }
        }

        $windowHistory = $this->sanitizeStringIntMap($history['day_windows'] ?? null);
        if ($windowHistory !== []) {
            $windows = $this->collectHistoryWindows($cluster, $mediaMap);
            if ($windows !== []) {
                $max = $this->maxVal($windowHistory);
                if ($max <= 0) {
                    $max = max(1, count($windowHistory));
                }

                $acc = 0.0;
                foreach ($windows as $window) {
                    $acc += $this->rarityFromCounts((int) ($windowHistory[$window] ?? 0), $max);
                }

                $components[] = $acc / count($windows);
            }
        }

        if ($components === []) {
            return 0.5;
        }

        return array_sum($components) / count($components);
    }

    /**
     * @return array<int,string>
     */
    private function staypointKeys(ClusterDraft $cluster): array
    {
        $params = $cluster->getParams();
        $meta   = $params['staypoints'] ?? null;
        if (!is_array($meta)) {
            return [];
        }

        $keys = $meta['keys'] ?? null;
        if (!is_array($keys)) {
            return [];
        }

        $filtered = [];
        foreach ($cluster->getMembers() as $id) {
            $key = $keys[$id] ?? null;
            if (is_string($key) && $key !== '') {
                $filtered[$id] = $key;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string,int>
     */
    private function sanitizeStringIntMap(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $filtered = [];
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_int($value)) {
                $filtered[$key] = $value;
                continue;
            }

            if (is_string($value) && $value !== '' && (string) (int) $value === $value) {
                $filtered[$key] = (int) $value;
            }
        }

        return $filtered;
    }

    private function maxVal(array $map): int
    {
        $max = 0;
        foreach ($map as $v) {
            if ($v > $max) {
                $max = $v;
            }
        }

        return $max;
    }

    private function rarityFromCounts(int $count, int $maxCount): float
    {
        if ($maxCount <= 0) {
            return 0.5;
        }

        $norm = 1.0 - (min($count, $maxCount) / (float) $maxCount);

        return max(0.0, min(1.0, $norm));
    }

    private function phashPrefix(?string $phash): ?string
    {
        if (!is_string($phash) || $phash === '') {
            return null;
        }

        $prefix = strtolower(substr($phash, 0, max(1, min(16, $this->phashPrefixNibbles))));

        return 'h:' . $prefix;
    }

    private function gridCell(float $lat, float $lon): string
    {
        $step = $this->gridStepDeg > 0.0 ? $this->gridStepDeg : 0.3;
        $latQ = (int) floor(($lat + 90.0) / $step);
        $lonQ = (int) floor(($lon + 180.0) / $step);

        return $latQ . ':' . $lonQ;
    }

    /**
     * @param ClusterDraft $c
     * @param array        $mediaMap
     *
     * @return list<int>
     *
     * @throws DateMalformedStringException
     */
    private function collectClusterDays(ClusterDraft $c, array $mediaMap): array
    {
        $tr  = $c->getParams()['time_range'] ?? null;
        $out = [];

        if (is_array($tr) && isset($tr['from'], $tr['to'])) {
            $from = (int) $tr['from'];
            $to   = (int) $tr['to'];
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }

            $utc      = new DateTimeZone('UTC');
            $startDay = (new DateTimeImmutable('@' . $from))->setTimezone($utc)->setTime(0, 0);
            $endDay   = (new DateTimeImmutable('@' . $to))->setTimezone($utc)->setTime(0, 0);

            if ($endDay->getTimestamp() < $startDay->getTimestamp()) {
                [$startDay, $endDay] = [$endDay, $startDay];
            }

            $span           = $startDay->diff($endDay);
            $uniqueDayCount = ($span->days ?? 0) + 1;

            if ($uniqueDayCount <= 7) {
                for ($d = $startDay; $d <= $endDay; $d = $d->modify('+1 day')) {
                    $out[] = (int) $d->format('z') + 1;
                }
            } else {
                $steps  = 7;
                $fromTs = $startDay->getTimestamp();
                $toTs   = $endDay->getTimestamp();
                $denom  = max(1, $steps - 1);

                for ($i = 0; $i < $steps; ++$i) {
                    $ts    = $fromTs + (int) round($i * ($toTs - $fromTs) / $denom);
                    $out[] = (int) (new DateTimeImmutable('@' . $ts))->setTimezone($utc)->format('z') + 1;
                }
            }

            return array_values(array_unique($out, SORT_NUMERIC));
        }

        foreach ($c->getMembers() as $id) {
            $m = $mediaMap[$id] ?? null;
            $t = $m instanceof Media ? $m->getTakenAt() : null;
            if ($t instanceof DateTimeImmutable) {
                $out[] = (int) $t->format('z') + 1;
            }
        }

        return array_values(array_unique($out, SORT_NUMERIC));
    }

    private function collectHistoryWindows(ClusterDraft $cluster, array $mediaMap): array
    {
        $windows   = [];
        $windowLen = max(1, $this->historyWindowHours);

        foreach ($cluster->getMembers() as $id) {
            $media = $mediaMap[$id] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $hour     = (int) $takenAt->format('G');
            $slot     = (int) floor($hour / $windowLen);
            $windowKey = $takenAt->format('md') . ':' . $slot;

            $windows[] = $windowKey;
        }

        return array_values(array_unique($windows));
    }

    private function majorityDevice(ClusterDraft $c, array $mediaMap): ?string
    {
        $cnt = [];
        foreach ($c->getMembers() as $id) {
            $m = $mediaMap[$id] ?? null;
            if (!$m instanceof Media) {
                continue;
            }

            $dev = $m->getCameraModel();
            if (is_string($dev) && $dev !== '') {
                $cnt[$dev] = ($cnt[$dev] ?? 0) + 1;
            }
        }

        $best  = null;
        $bestN = 0;
        foreach ($cnt as $k => $n) {
            if ($n > $bestN) {
                $best  = $k;
                $bestN = $n;
            }
        }

        return $best;
    }

    private function majorityPhashPrefix(ClusterDraft $c, array $mediaMap): ?string
    {
        $nibbles     = max(1, min(16, $this->phashPrefixNibbles));
        $cnt         = [];
        $prefixByKey = [];

        foreach ($c->getMembers() as $id) {
            $m = $mediaMap[$id] ?? null;
            if (!$m instanceof Media) {
                continue;
            }

            $ph = $m->getPhash();
            if (!is_string($ph) || $ph === '') {
                continue;
            }

            $prefix = strtolower(substr($ph, 0, $nibbles));
            $key               = 'h:' . $prefix;
            $cnt[$key]         = ($cnt[$key] ?? 0) + 1;
            $prefixByKey[$key] = $prefix;
        }

        $bestKey = null;
        $bestN   = 0;
        foreach ($cnt as $k => $n) {
            if ($n > $bestN) {
                $bestN   = $n;
                $bestKey = $k;
            }
        }

        if ($bestKey === null) {
            return null;
        }

        return $prefixByKey[$bestKey] ?? null;
    }
}
