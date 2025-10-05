<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Clusterer\Support\PersonSignatureHelper;
use MagicSunday\Memories\Entity\Media;

use function array_keys;
use function count;
use function is_array;
use function is_int;
use function ksort;

/**
 * Aggregates important person appearances for each day summary.
 *
 * @phpstan-import-type DaySummary from \MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage
 */
final class CohortPresenceStage implements DaySummaryStageInterface
{
    /**
     * @var array<int, true>
     */
    private array $importantPersons = [];

    /**
     * @var array<int, int>
     */
    private array $aliasToCanonical = [];

    private PersonSignatureHelper $personSignatureHelper;

    /**
     * @param list<int>           $importantPersonIds
     * @param array<int, list<int>> $fallbackPersonIds
     */
    public function __construct(
        array $importantPersonIds = [],
        array $fallbackPersonIds = [],
        ?PersonSignatureHelper $personSignatureHelper = null,
    ) {
        $this->personSignatureHelper = $personSignatureHelper ?? new PersonSignatureHelper();

        foreach ($importantPersonIds as $personId) {
            if (!is_int($personId) || $personId <= 0) {
                throw new InvalidArgumentException('importantPersonIds must contain positive integers.');
            }

            $this->importantPersons[$personId] = true;
            $this->aliasToCanonical[$personId] = $personId;
        }

        foreach ($fallbackPersonIds as $canonical => $aliases) {
            if (!is_int($canonical) || $canonical <= 0) {
                throw new InvalidArgumentException('fallbackPersonIds keys must be positive integers.');
            }

            if ($aliases === [] || !is_array($aliases)) {
                continue;
            }

            if (!isset($this->importantPersons[$canonical])) {
                $this->importantPersons[$canonical] = true;
                $this->aliasToCanonical[$canonical] = $canonical;
            }

            foreach ($aliases as $alias) {
                if (!is_int($alias) || $alias <= 0) {
                    throw new InvalidArgumentException('fallbackPersonIds entries must be positive integers.');
                }

                $this->aliasToCanonical[$alias] = $canonical;
            }
        }
    }

    /**
     * @param array<string, DaySummary>                                                               $days
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     *
     * @return array<string, DaySummary>
     */
    public function process(array $days, array $home): array
    {
        if ($days === [] || $this->importantPersons === []) {
            return $days;
        }

        $totalImportant = count($this->importantPersons);

        foreach (array_keys($days) as $key) {
            $summary = $days[$key];

            $frequency = [];
            $present   = [];

            foreach ($summary['members'] as $media) {
                $personIds = $this->personSignatureHelper->personIds($media);

                foreach ($personIds as $personId) {
                    $canonicalId = $this->aliasToCanonical[$personId] ?? null;
                    if ($canonicalId === null) {
                        continue;
                    }

                    $present[$canonicalId] = true;
                    if (!isset($frequency[$canonicalId])) {
                        $frequency[$canonicalId] = 0;
                    }

                    ++$frequency[$canonicalId];
                }
            }

            if ($frequency !== []) {
                ksort($frequency);
            }

            $ratio = $totalImportant > 0 ? count($present) / $totalImportant : 0.0;
            if ($ratio > 1.0) {
                $ratio = 1.0;
            }

            $days[$key]['cohortPresenceRatio'] = $ratio;
            $days[$key]['cohortMembers']       = $frequency;
        }

        return $days;
    }
}
