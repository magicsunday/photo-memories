<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;

use function array_unshift;
use function count;
use function in_array;

/**
 * Adds potential transport days to a vacation run.
 */
final class TransportDayExtender
{
    use ConsecutiveDaysTrait;

    public function __construct(
        private float $transitRatioThreshold = 0.6,
        private float $transitSpeedThreshold = 90.0,
        private int $leanPhotoThreshold = 2,
    ) {
        if ($this->transitRatioThreshold < 0.0 || $this->transitRatioThreshold > 1.0) {
            throw new InvalidArgumentException('transitRatioThreshold must be between 0.0 and 1.0.');
        }

        if ($this->transitSpeedThreshold <= 0.0) {
            throw new InvalidArgumentException('transitSpeedThreshold must be greater than 0.');
        }

        if ($this->leanPhotoThreshold < 0) {
            throw new InvalidArgumentException('leanPhotoThreshold must be zero or positive.');
        }
    }

    /**
     * @param list<string>                                              $run
     * @param list<string>                                                      $orderedKeys
     * @param array<string, int>                                                $indexByKey
     * @param array<string, array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,transitRatio?:float,avgSpeedKmh?:float,maxSpeedKmh?:float,photoCount?:int}> $days
     *
     * @return list<string>
     */
    public function extend(array $run, array $orderedKeys, array $indexByKey, array $days): array
    {
        if ($run === []) {
            return $run;
        }

        $extended = $run;

        $firstKey   = $run[0];
        $firstIndex = $indexByKey[$firstKey] ?? null;
        if ($firstIndex !== null && $firstIndex > 0) {
            $candidateKey = $orderedKeys[$firstIndex - 1];
            if (
                !in_array($candidateKey, $extended, true)
                && $this->shouldExtendWithDay($candidateKey, $firstKey, $days)
                && $this->areSequentialDays($candidateKey, $firstKey, $days)
            ) {
                array_unshift($extended, $candidateKey);
            }
        }

        $lastKey      = $run[count($run) - 1];
        $lastIndex    = $indexByKey[$lastKey] ?? null;
        $orderedCount = count($orderedKeys);
        if ($lastIndex !== null && $lastIndex + 1 < $orderedCount) {
            $candidateKey = $orderedKeys[$lastIndex + 1];
            if (
                !in_array($candidateKey, $extended, true)
                && $this->shouldExtendWithDay($candidateKey, $lastKey, $days)
                && $this->areSequentialDays($lastKey, $candidateKey, $days)
            ) {
                $extended[] = $candidateKey;
            }
        }

        return $extended;
    }

    /**
     * @param array<string, array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,transitRatio?:float,avgSpeedKmh?:float,maxSpeedKmh?:float,photoCount?:int}> $days
     */
    private function shouldExtendWithDay(string $candidateKey, string $anchorKey, array $days): bool
    {
        $candidate = $days[$candidateKey] ?? null;
        if ($candidate === null) {
            return false;
        }

        if (($candidate['hasAirportPoi'] ?? false) || ($candidate['hasHighSpeedTransit'] ?? false)) {
            return true;
        }

        if ($this->isTransitHeavy($candidate)) {
            return true;
        }

        if ($this->isLeanDay($candidate)) {
            $anchor = $days[$anchorKey] ?? null;
            if ($anchor !== null && $this->isTransitHeavy($anchor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,transitRatio?:float,avgSpeedKmh?:float,maxSpeedKmh?:float,photoCount?:int} $summary
     */
    private function isTransitHeavy(array $summary): bool
    {
        if (($summary['hasHighSpeedTransit'] ?? false) === true) {
            return true;
        }

        $ratio = (float) ($summary['transitRatio'] ?? 0.0);
        if ($ratio >= $this->transitRatioThreshold) {
            return true;
        }

        $avgSpeed = (float) ($summary['avgSpeedKmh'] ?? 0.0);
        if ($avgSpeed >= $this->transitSpeedThreshold) {
            return true;
        }

        $maxSpeed = (float) ($summary['maxSpeedKmh'] ?? 0.0);
        if ($maxSpeed >= $this->transitSpeedThreshold) {
            return true;
        }

        return false;
    }

    /**
     * @param array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,photoCount?:int} $summary
     */
    private function isLeanDay(array $summary): bool
    {
        $dominantStaypoints = $summary['dominantStaypoints'] ?? [];
        if ($dominantStaypoints !== []) {
            return false;
        }

        return (int) ($summary['photoCount'] ?? 0) <= $this->leanPhotoThreshold;
    }

    /**
     * @param string                                 $previous
     * @param string                                 $current
     * @param array<string, array{isSynthetic:bool}> $days
     *
     * @return bool
     *
     * @throws DateMalformedStringException
     */
    public function areSequentialDays(string $previous, string $current, array $days): bool
    {
        return $this->checkSequentialDays($previous, $current, $days);
    }

    /**
     * @param string                                 $previous
     * @param string                                 $current
     * @param array<string, array{isSynthetic:bool}> $days
     *
     * @return bool
     *
     * @throws DateMalformedStringException
     */
    private function checkSequentialDays(string $previous, string $current, array $days): bool
    {
        if ($this->isNextDay($previous, $current)) {
            return true;
        }

        $timezone = new DateTimeZone('UTC');
        $start    = DateTimeImmutable::createFromFormat('!Y-m-d', $previous, $timezone);
        $end      = DateTimeImmutable::createFromFormat('!Y-m-d', $current, $timezone);

        if ($start === false || $end === false || $start > $end) {
            return false;
        }

        $cursor = $start->modify('+1 day');
        while ($cursor < $end) {
            $key     = $cursor->format('Y-m-d');
            $summary = $days[$key] ?? null;
            if ($summary === null) {
                return false;
            }

            if (($summary['isSynthetic'] ?? false) === false) {
                return false;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return true;
    }
}
