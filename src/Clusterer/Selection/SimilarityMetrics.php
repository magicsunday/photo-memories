<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Selection;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_intersect;
use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function hexdec;
use function implode;
use function min;
use function spl_object_id;
use function strlen;

/**
 * Computes similarity metrics between media entities with lightweight caching.
 */
final class SimilarityMetrics
{
    /** @var array<string, int> */
    private array $timeCache = [];

    /** @var array<string, float|null> */
    private array $distanceCache = [];

    /** @var array<string, int|null> */
    private array $phashCache = [];

    /** @var array<string, float> */
    private array $personCache = [];

    public function secondsBetween(Media $a, Media $b): int
    {
        $key = $this->pairKey($a, $b);
        if (isset($this->timeCache[$key])) {
            return $this->timeCache[$key];
        }

        $timeA = $this->resolveTimestamp($a);
        $timeB = $this->resolveTimestamp($b);

        $delta = abs($timeA - $timeB);
        $this->timeCache[$key] = $delta;

        return $delta;
    }

    public function phashDistance(Media $a, Media $b): ?int
    {
        $key = $this->pairKey($a, $b);
        if (array_key_exists($key, $this->phashCache)) {
            return $this->phashCache[$key];
        }

        $hashA = $a->getPhash64();
        $hashB = $b->getPhash64();

        if ($hashA === null || $hashB === null) {
            $hashA = $hashA ?? $a->getPhash();
            $hashB = $hashB ?? $b->getPhash();
        }

        if ($hashA === null || $hashB === null) {
            $this->phashCache[$key] = null;

            return null;
        }

        $len  = min(strlen($hashA), strlen($hashB));
        $dist = 0;

        for ($i = 0; $i < $len; ++$i) {
            $dist += $this->bitCount(hexdec($hashA[$i]) ^ hexdec($hashB[$i]));
        }

        $dist += (int) (abs(strlen($hashA) - strlen($hashB)) * 4);

        $this->phashCache[$key] = $dist;

        return $dist;
    }

    public function geoDistanceMeters(Media $a, Media $b): ?float
    {
        $key = $this->pairKey($a, $b);
        if (array_key_exists($key, $this->distanceCache)) {
            return $this->distanceCache[$key];
        }

        $latA = $a->getGpsLat();
        $lonA = $a->getGpsLon();
        $latB = $b->getGpsLat();
        $lonB = $b->getGpsLon();

        if ($latA === null || $lonA === null || $latB === null || $lonB === null) {
            $this->distanceCache[$key] = null;

            return null;
        }

        $distance = MediaMath::haversineDistanceInMeters($latA, $lonA, $latB, $lonB);
        $this->distanceCache[$key] = $distance;

        return $distance;
    }

    public function personOverlap(Media $a, Media $b): float
    {
        $key = $this->pairKey($a, $b);
        if (isset($this->personCache[$key])) {
            return $this->personCache[$key];
        }

        $personsA = $a->getPersons();
        $personsB = $b->getPersons();

        if ($personsA === null || $personsB === null) {
            $this->personCache[$key] = 0.0;

            return 0.0;
        }

        $unique  = array_values(array_unique([...$personsA, ...$personsB]));
        $overlap = array_intersect($personsA, $personsB);

        $score = 0.0;
        if ($unique !== []) {
            $score = count($overlap) / count($unique);
        }

        $this->personCache[$key] = (float) $score;

        return (float) $score;
    }

    public function shareSameDevice(Media $a, Media $b): bool
    {
        return $this->deviceFingerprint($a) === $this->deviceFingerprint($b);
    }

    private function pairKey(Media $a, Media $b): string
    {
        $first  = spl_object_id($a);
        $second = spl_object_id($b);

        if ($first > $second) {
            $tmp    = $first;
            $first  = $second;
            $second = $tmp;
        }

        return $first . '-' . $second;
    }

    private function deviceFingerprint(Media $media): string
    {
        $parts = [
            $media->getCameraMake() ?? '',
            $media->getCameraModel() ?? '',
            $media->getCameraBodySerial() ?? '',
        ];

        return implode('|', $parts);
    }

    private function resolveTimestamp(Media $media): int
    {
        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            return $takenAt->getTimestamp();
        }

        return $media->getCreatedAt()->getTimestamp();
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
