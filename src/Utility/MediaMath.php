<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;

/**
 * Small geo/time helpers for clustering.
 */
final class MediaMath
{
    private const float EARTH_RADIUS_KM = 6371.0088;

    public static function secondsBetween(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return \abs($a->getTimestamp() - $b->getTimestamp());
    }

    public static function haversineDistanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = \deg2rad($lat2 - $lat1);
        $dLon = \deg2rad($lon2 - $lon1);

        $a = \sin($dLat / 2) ** 2
            + \cos(\deg2rad($lat1)) * \cos(\deg2rad($lat2)) * \sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * 1000 * \asin(\min(1.0, \sqrt($a)));
    }

    /**
     * @param list<Media> $items
     * @return array{lat: float, lon: float}
     */
    public static function centroid(array $items): array
    {
        $sumLat = 0.0;
        $sumLon = 0.0;
        $n = 0;

        foreach ($items as $m) {
            $lat = $m->getGpsLat();
            $lon = $m->getGpsLon();

            if ($lat !== null && $lon !== null) {
                $sumLat += $lat;
                $sumLon += $lon;

                $n++;
            }
        }

        if ($n === 0) {
            return ['lat' => 0, 'lon' => 0];
        }

        return ['lat' => $sumLat / $n, 'lon' => $sumLon / $n];
    }

    /**
     * @param list<Media> $members
     * @return array{from:int,to:int}
     */
    public static function timeRange(array $members): array
    {
        $ts = \array_values(\array_filter(
            \array_map(static fn (Media $m): ?int => $m->getTakenAt()?->getTimestamp(), $members),
            static fn (?int $t): bool => $t !== null
        ));

        \sort($ts);

        $from = $ts[0] ?? 0;
        $to   = $ts[\count($ts) - 1] ?? $from;

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Compute a reliable time range from media items.
     *
     * Returns null if there are too few valid timestamps, coverage is too low,
     * or timestamps are earlier than $minValidYear-01-01.
     *
     * @param list<Media> $items
     * @return array{from:int,to:int}|null
     */
    public static function timeRangeReliable(
        array $items,
        int $minSamples = 3,
        float $minCoverage = 0.6,
        int $minValidYear = 1990
    ): ?array {
        if ($minSamples < 1) {
            $minSamples = 1;
        }

        if ($minCoverage <= 0.0) {
            $minCoverage = 0.01;
        }

        $total = \count($items);
        if ($total === 0) {
            return null;
        }

        $ts = [];
        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if ($t instanceof DateTimeImmutable) {
                $ts[] = $t->getTimestamp();
            }
        }

        $valid = \count($ts);
        if ($valid < $minSamples) {
            return null;
        }

        $coverage = $valid / (float) $total;
        if ($coverage < $minCoverage) {
            return null;
        }

        \sort($ts, \SORT_NUMERIC);
        $from = $ts[0];
        $to   = $ts[\count($ts) - 1];

        // Reject obviously bogus dates (e.g. 1970)
        $minTs = (new DateTimeImmutable(\sprintf('%04d-01-01', $minValidYear)))->getTimestamp();
        if ($from < $minTs || $to < $minTs) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }
}
