<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateTimeImmutable;
use DateTimeZone;
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

    /**
     * @param list<string>                           $run
     * @param list<string>                           $orderedKeys
     * @param array<string, int>                     $indexByKey
     * @param array<string, array{hasAirportPoi:bool,isSynthetic:bool}> $days
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
                && ($days[$candidateKey]['hasAirportPoi'] ?? false)
                && $this->areSequentialDays($candidateKey, $firstKey, $days)
            ) {
                array_unshift($extended, $candidateKey);
            }
        }

        $lastKey   = $run[count($run) - 1];
        $lastIndex = $indexByKey[$lastKey] ?? null;
        $orderedCount = count($orderedKeys);
        if ($lastIndex !== null && $lastIndex + 1 < $orderedCount) {
            $candidateKey = $orderedKeys[$lastIndex + 1];
            if (
                !in_array($candidateKey, $extended, true)
                && ($days[$candidateKey]['hasAirportPoi'] ?? false)
                && $this->areSequentialDays($lastKey, $candidateKey, $days)
            ) {
                $extended[] = $candidateKey;
            }
        }

        return $extended;
    }

    /**
     * @param array<string, array{isSynthetic:bool}> $days
     */
    public function areSequentialDays(string $previous, string $current, array $days): bool
    {
        return $this->checkSequentialDays($previous, $current, $days);
    }

    /**
     * @param array<string, array{isSynthetic:bool}> $days
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
            $key = $cursor->format('Y-m-d');
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
