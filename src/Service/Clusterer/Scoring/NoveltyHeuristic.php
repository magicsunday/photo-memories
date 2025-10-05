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

use function array_unique;
use function array_values;
use function count;
use function floor;
use function is_array;
use function is_float;
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
 *  - place rarity: quantized lat/lon cell frequency
 *  - time rarity : day-of-year frequency
 *  - device rarity: camera model frequency
 *  - content rarity: pHash high-nibble prefix frequency.
 *
 * No external models; runs on fields already present on Media.
 */
final class NoveltyHeuristic extends AbstractClusterScoreHeuristic
{
    /** @var array<string,mixed>|null $stats */
    private ?array $stats = null;

    public function __construct(
        private float $gridStepDeg = 0.5,     // ~55 km
        private int $phashPrefixNibbles = 4,  // 4 hex chars -> 16 bit
        /** @var array{place: float, time: float, device: float, content: float} $weights */
        private array $weights = [
            'place'   => 0.35,
            'time'    => 0.25,
            'device'  => 0.20,
            'content' => 0.20,
        ],
    ) {
    }

    public function prepare(array $clusters, array $mediaMap): void
    {
        $this->stats = $this->buildCorpusStats($mediaMap);
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params = $cluster->getParams();
        $novelty = $this->floatOrNull($params['novelty'] ?? null);
        if ($novelty === null) {
            $stats        = $this->stats ?? $this->buildCorpusStats($mediaMap);
            $novelty      = $this->computeNovelty($cluster, $mediaMap, $stats);
            $this->stats  = $stats;
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
     *
     * @return array{
     *   total:int,
     *   device: array<string,int>,
     *   grid:   array<string,int>,
     *   doy:    array<int,int>,        // 1..366
     *   phash:  array<string,int>,     // prefix -> count
     *   max:    array{device:int,grid:int,doy:int,phash:int}
     * }
     */
    public function buildCorpusStats(array $mediaMap): array
    {
        $device = [];
        $grid   = [];
        $doy    = [];
        $phash  = [];

        $total = 0;
        foreach ($mediaMap as $m) {
            ++$total;

            // device
            $dev = $m->getCameraModel();
            if (is_string($dev) && $dev !== '') {
                $device[$dev] = ($device[$dev] ?? 0) + 1;
            }

            // grid (only if GPS present)
            $lat = $m->getGpsLat();
            $lon = $m->getGpsLon();
            if ($lat !== null && $lon !== null) {
                $grid[$this->gridCell((float) $lat, (float) $lon)] = ($grid[$this->gridCell((float) $lat, (float) $lon)] ?? 0) + 1;
            }

            // day-of-year (only if time present)
            $t = $m->getTakenAt();
            if ($t !== null) {
                $d       = (int) $t->format('z') + 1; // 1..366
                $doy[$d] = ($doy[$d] ?? 0) + 1;
            }

            // pHash prefix
            $ph = $m->getPhash();
            if (is_string($ph) && $ph !== '') {
                $prefix      = strtolower(
                    substr(
                        $ph,
                        0,
                        max(
                            1,
                            min(
                                16,
                                $this->phashPrefixNibbles
                            )
                        )
                    )
                );
                $key         = 'h:' . $prefix;
                $phash[$key] = ($phash[$key] ?? 0) + 1;
            }
        }

        $max = [
            'device' => $this->maxVal($device),
            'grid'   => $this->maxVal($grid),
            'doy'    => $this->maxVal($doy),
            'phash'  => $this->maxVal($phash),
        ];

        return [
            'total'  => $total,
            'device' => $device,
            'grid'   => $grid,
            'doy'    => $doy,
            'phash'  => $phash,
            'max'    => $max,
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
     * @throws DateMalformedStringException
     */
    public function computeNovelty(ClusterDraft $cluster, array $mediaMap, array $stats): float
    {
        // --- place rarity: use centroid's cell frequency
        $centroid = $cluster->getCentroid();
        $place    = 0.5; // neutral default
        if (isset($centroid['lat'], $centroid['lon']) && is_float($centroid['lat']) && is_float($centroid['lon'])) {
            $cell  = $this->gridCell($centroid['lat'], $centroid['lon']);
            $cnt   = (int) ($stats['grid'][$cell] ?? 0);
            $max   = (int) ($stats['max']['grid'] ?? 0);
            $place = $this->rarityFromCounts($cnt, $max);
        }

        // --- time rarity: average rarity across involved days (from time_range if vorhanden, sonst per members)
        $time = 0.5;
        $days = $this->collectClusterDays($cluster, $mediaMap);
        if ($days !== []) {
            $acc = 0.0;
            foreach ($days as $d) {
                $cnt = (int) ($stats['doy'][$d] ?? 0);
                $max = (int) ($stats['max']['doy'] ?? 0);
                $acc += $this->rarityFromCounts($cnt, $max);
            }

            $time = $acc / count($days);
        }

        // --- device rarity: take majority device inside cluster
        $device   = 0.5;
        $majorDev = $this->majorityDevice($cluster, $mediaMap);
        if ($majorDev !== null) {
            $cnt    = (int) ($stats['device'][$majorDev] ?? 0);
            $max    = (int) ($stats['max']['device'] ?? 0);
            $device = $this->rarityFromCounts($cnt, $max);
        }

        // --- content rarity: majority pHash prefix
        $content = 0.5;
        $prefix  = $this->majorityPhashPrefix($cluster, $mediaMap);
        if ($prefix !== null) {
            $cnt     = (int) ($stats['phash']['h:' . $prefix] ?? 0);
            $max     = (int) ($stats['max']['phash'] ?? 0);
            $content = $this->rarityFromCounts($cnt, $max);
        }

        return
            $this->weights['place'] * $place +
            $this->weights['time'] * $time +
            $this->weights['device'] * $device +
            $this->weights['content'] * $content;
    }

    // ---- helpers -----------------------------------------------------------

    /** @param array<string,int> $map */
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

    /** Frequency → rarity in [0..1], 1 means rare. */
    private function rarityFromCounts(int $count, int $maxCount): float
    {
        if ($maxCount <= 0) {
            return 0.5; // neutral if we have no reference
        }

        // invert + clamp; small offset keeps 0-count clearly rare
        $norm = 1.0 - (min($count, $maxCount) / (float) $maxCount);

        return max(0.0, min(1.0, $norm));
    }

    private function gridCell(float $lat, float $lon): string
    {
        $step = $this->gridStepDeg > 0.0 ? $this->gridStepDeg : 0.5;
        $latQ = (int) floor(($lat + 90.0) / $step);
        $lonQ = (int) floor(($lon + 180.0) / $step);

        return $latQ . ':' . $lonQ;
    }

    /**
     * @param ClusterDraft $c
     * @param array        $mediaMap
     *
     * @return list<int>
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

            // Use up to 7 representative days across the range to stay cheap
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

        // Fallback: derive days from members
        foreach ($c->getMembers() as $id) {
            $m = $mediaMap[$id] ?? null;
            $t = $m instanceof Media ? $m->getTakenAt() : null;
            if ($t instanceof DateTimeImmutable) {
                $out[] = (int) $t->format('z') + 1;
            }
        }

        return array_values(array_unique($out, SORT_NUMERIC));
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
            if (!is_string($ph)) {
                continue;
            }

            if ($ph === '') {
                continue;
            }

            $prefix            = strtolower(
                substr(
                    $ph,
                    0,
                    $nibbles
                )
            );
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
