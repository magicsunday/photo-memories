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
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Quality\MediaQualityAggregator;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function ceil;
use function count;
use function floor;
use function intdiv;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sort;
use function sprintf;
use function usort;

/**
 * Greedy vacation selector that balances quality, diversity and telemetry transparency.
 *
 * @phpstan-import-type DaySummary from \MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage
 * @phpstan-import-type HomeDescriptor from MemberSelectorInterface
 * @phpstan-type Candidate array{
 *     media: Media,
 *     day: string,
 *     summary: DaySummary,
 *     timestamp: int,
 *     slot: int,
 *     score: float,
 *     quality: float,
 *     staypointKey: string|null,
 *     burstId: string|null,
 *     origin: string,
 * }
 */
final class VacationMemberSelector implements MemberSelectorInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $telemetry = [];

    /**
     * @var array<string, int>
     */
    private array $dayCaps = [];

    /**
     * @var array<string, int>
     */
    private array $daySpacingThresholds = [];

    private int $effectiveMaxPerStaypoint = 0;

    private int $effectivePhashMin = 0;

    private int $basePerDayCap = 0;

    private readonly VacationSelectionOptions $defaultOptions;

    public function __construct(
        private readonly MediaQualityAggregator $qualityAggregator,
        private readonly SimilarityMetrics $metrics,
        ?VacationSelectionOptions $defaultOptions = null,
    ) {
        $this->defaultOptions = $defaultOptions ?? new VacationSelectionOptions();
    }

    /**
     * @param array<string, DaySummary> $daySummaries
     * @param HomeDescriptor            $home
     */
    public function select(array $daySummaries, array $home, ?VacationSelectionOptions $options = null): SelectionResult
    {
        $options ??= $this->defaultOptions;
        $minimumTotal = max(1, min($options->targetTotal, $options->minimumTotal));

        [$members, $telemetry] = $this->attemptSelection($daySummaries, $options);

        $finalMembers   = $members;
        $finalTelemetry = $telemetry;
        $relaxations    = [];
        $attemptOptions = $options;
        $spacingRelaxedToZero = false;
        $phashRelaxedToZero   = false;

        if (count($finalMembers) < $minimumTotal) {
            $relaxationPlan = [
                function (VacationSelectionOptions $current): ?array {
                    if ($current->minSpacingSeconds === 0) {
                        return null;
                    }

                    $next = $this->cloneOptions($current, ['minSpacingSeconds' => 0]);

                    return [
                        'options' => $next,
                        'changes' => [[
                            'rule' => 'min_spacing_seconds',
                            'from' => $current->minSpacingSeconds,
                            'to'   => $next->minSpacingSeconds,
                        ]],
                    ];
                },
                function (VacationSelectionOptions $current): ?array {
                    if ($current->phashMinHamming === 0) {
                        return null;
                    }

                    $next = $this->cloneOptions($current, ['phashMinHamming' => 0]);

                    return [
                        'options' => $next,
                        'changes' => [[
                            'rule' => 'phash_min_hamming',
                            'from' => $current->phashMinHamming,
                            'to'   => $next->phashMinHamming,
                        ]],
                    ];
                },
                function (VacationSelectionOptions $current): ?array {
                    $changes   = [];
                    $overrides = [];

                    if ($current->maxPerDay < $current->targetTotal) {
                        $overrides['maxPerDay'] = $current->targetTotal;
                        $changes[] = [
                            'rule' => 'max_per_day',
                            'from' => $current->maxPerDay,
                            'to'   => $current->targetTotal,
                        ];
                    }

                    if ($current->maxPerStaypoint < $current->targetTotal) {
                        $overrides['maxPerStaypoint'] = $current->targetTotal;
                        $changes[] = [
                            'rule' => 'max_per_staypoint',
                            'from' => $current->maxPerStaypoint,
                            'to'   => $current->targetTotal,
                        ];
                    }

                    if ($overrides === []) {
                        return null;
                    }

                    $next = $this->cloneOptions($current, $overrides);

                    return [
                        'options' => $next,
                        'changes' => $changes,
                    ];
                },
            ];

            foreach ($relaxationPlan as $stage) {
                if (count($finalMembers) >= $minimumTotal) {
                    break;
                }

                $result = $stage($attemptOptions);
                if ($result === null) {
                    continue;
                }

                $attemptOptions = $result['options'];
                foreach ($result['changes'] as $change) {
                    $relaxations[] = $change;

                    if (($change['rule'] ?? null) === 'min_spacing_seconds' && (int) ($change['to'] ?? -1) === 0) {
                        $spacingRelaxedToZero = true;
                    }

                    if (($change['rule'] ?? null) === 'phash_min_hamming' && (int) ($change['to'] ?? -1) === 0) {
                        $phashRelaxedToZero = true;
                    }
                }

                [$finalMembers, $finalTelemetry] = $this->attemptSelection($daySummaries, $attemptOptions);
            }
        }

        $finalTelemetry['relaxations']        = $relaxations;
        $finalTelemetry['minimum_total']      = $minimumTotal;
        $finalTelemetry['minimum_total_met']  = count($finalMembers) >= $minimumTotal;

        $thresholds = $finalTelemetry['thresholds'] ?? null;
        if (is_array($thresholds)) {
            $thresholds['spacing_relaxed_to_zero'] = ($thresholds['spacing_relaxed_to_zero'] ?? false) || $spacingRelaxedToZero;
            $thresholds['phash_relaxed_to_zero']   = ($thresholds['phash_relaxed_to_zero'] ?? false) || $phashRelaxedToZero;
            $finalTelemetry['thresholds']          = $thresholds;
        } else {
            $finalTelemetry['thresholds'] = [
                'spacing_relaxed_to_zero' => $spacingRelaxedToZero,
                'phash_relaxed_to_zero'   => $phashRelaxedToZero,
            ];
        }

        $this->telemetry = $finalTelemetry;

        return new SelectionResult($finalMembers, $this->telemetry);
    }

    /**
     * @param array<string, DaySummary> $daySummaries
     *
     * @return array{0: list<Media>, 1: array<string, mixed>}
     */
    private function attemptSelection(array $daySummaries, VacationSelectionOptions $options): array
    {
        $this->dayCaps                  = [];
        $this->daySpacingThresholds     = [];
        $this->effectiveMaxPerStaypoint = $options->maxPerStaypoint;
        $this->effectivePhashMin        = $options->phashMinHamming;
        $this->basePerDayCap            = $options->maxPerDay;

        $this->telemetry = [
            'prefilter_total'             => 0,
            'prefilter_no_show'           => 0,
            'prefilter_low_quality'       => 0,
            'prefilter_quality_floor'     => 0,
            'burst_collapsed'             => 0,
            'day_limit_rejections'        => 0,
            'staypoint_rejections'        => 0,
            'spacing_rejections'          => 0,
            'near_duplicate_blocked'      => 0,
            'near_duplicate_replacements' => 0,
            'fallback_used'               => 0,
            'face_detection_available'          => $options->faceDetectionAvailable,
            'face_detection_people_weight_adjusted' => !$options->faceDetectionAvailable,
            'face_bonus'                        => $options->faceBonus,
            'video_bonus'                       => $options->videoBonus,
            'people_balance_enabled'        => $options->enablePeopleBalance,
            'people_balance_weight'         => $options->peopleBalanceWeight,
            'people_balance_repeat_penalty' => $options->repeatPenalty,
            'people_balance_considered'     => 0,
            'people_balance_penalized'      => 0,
            'people_balance_bonuses'        => 0,
            'people_balance_rejected'       => 0,
            'people_balance_accepted'       => 0,
            'people_balance_counts'         => [],
            'people_balance_target_cap'     => $options->enablePeopleBalance
                ? max(1, (int) ceil($options->targetTotal * $options->peopleBalanceWeight))
                : null,
            'relaxations'                 => [],
            'thresholds'                  => [
                'run_day_count'           => count($daySummaries),
                'raw_per_day_cap'         => null,
                'base_per_day_cap'        => null,
                'day_caps'                => [],
                'day_categories'          => [],
                'day_spacing_seconds'     => [],
                'max_per_staypoint'          => $options->maxPerStaypoint,
                'phash_min_effective'        => $options->phashMinHamming,
                'phash_percentile_ratio'     => $options->phashPercentile,
                'phash_percentile_threshold' => null,
                'phash_sample_count'         => 0,
                'spacing_relaxed_to_zero' => false,
                'phash_relaxed_to_zero'   => false,
            ],
            'metrics'                     => [
                'phash_samples' => [],
            ],
        ];

        if ($daySummaries === []) {
            return [[], $this->telemetry];
        }

        $filtered = $this->prefilter($daySummaries, $options);
        if ($filtered['unique'] === [] && $filtered['fallback'] === []) {
            return [[], $this->telemetry];
        }

        $primaryByDay  = $filtered['unique'];
        $fallbackByDay = $filtered['fallback'];

        $dayOrder = array_keys($primaryByDay + $fallbackByDay);
        sort($dayOrder);

        $runDays      = count($dayOrder);
        $rawPerDayCap = $runDays > 0 ? (int) ceil($options->targetTotal / $runDays) : 1;
        if ($rawPerDayCap < 1) {
            $rawPerDayCap = 1;
        }

        $basePerDayCap = min($options->maxPerDay, $rawPerDayCap);
        if ($basePerDayCap < 1) {
            $basePerDayCap = 1;
        }

        $this->basePerDayCap            = $basePerDayCap;
        $this->dayCaps                  = $this->buildDayCaps($daySummaries, $dayOrder, $options, $basePerDayCap);
        $this->effectiveMaxPerStaypoint = $this->resolveStaypointCap($options, $basePerDayCap);

        $samples              = $this->samplePhashDistances($primaryByDay, $fallbackByDay, $options);
        $samplesForTelemetry  = $samples;
        sort($samplesForTelemetry);
        if (count($samplesForTelemetry) > 50) {
            $samplesForTelemetry = array_slice($samplesForTelemetry, 0, 50);
        }

        $this->telemetry['metrics']['phash_samples'] = $samplesForTelemetry;

        $adaptivePercentile   = $this->resolvePhashPercentile($samples, $options->phashPercentile);
        $this->effectivePhashMin = max($options->phashMinHamming, $adaptivePercentile);

        $this->telemetry['thresholds']['run_day_count']       = $runDays;
        $this->telemetry['thresholds']['raw_per_day_cap']     = $rawPerDayCap;
        $this->telemetry['thresholds']['base_per_day_cap']    = $basePerDayCap;
        $this->telemetry['thresholds']['day_caps']            = $this->dayCaps;
        $this->telemetry['thresholds']['day_categories']      = $this->collectDayCategories($daySummaries, $dayOrder);
        $this->telemetry['thresholds']['day_spacing_seconds'] = $this->daySpacingThresholds;
        $this->telemetry['thresholds']['max_per_staypoint']   = $this->effectiveMaxPerStaypoint;
        $this->telemetry['thresholds']['phash_min_effective'] = $this->effectivePhashMin;
        $this->telemetry['thresholds']['phash_percentile_ratio']     = $options->phashPercentile;
        $this->telemetry['thresholds']['phash_percentile_threshold'] = $adaptivePercentile;
        $this->telemetry['thresholds']['phash_sample_count']         = count($samples);

        foreach ($primaryByDay as $day => $list) {
            $primaryByDay[$day] = $this->sortPrimary($list);
        }

        foreach ($fallbackByDay as $day => $list) {
            $fallbackByDay[$day] = $this->sortFallback($list);
        }

        $primaryOrder  = $this->roundRobin($primaryByDay, $dayOrder);
        $fallbackOrder = $this->roundRobin($fallbackByDay, $dayOrder);

        $selected        = [];
        $dayCounts       = [];
        $staypointCounts = [];
        $personCounts    = [];

        foreach ($primaryOrder as $candidate) {
            if ($this->considerCandidate($candidate, $selected, $dayCounts, $staypointCounts, $personCounts, $options)) {
                if (count($selected) >= $options->targetTotal) {
                    break;
                }
            }
        }

        if (count($selected) < $options->targetTotal) {
            foreach ($fallbackOrder as $candidate) {
                if ($this->considerCandidate($candidate, $selected, $dayCounts, $staypointCounts, $personCounts, $options, true)) {
                    if (count($selected) >= $options->targetTotal) {
                        break;
                    }
                }
            }
        }

        usort($selected, [$this, 'compareCandidates']);

        $members = [];
        foreach ($selected as $item) {
            $members[] = $item['media'];
        }

        $this->telemetry['selected_total']        = count($members);
        $this->telemetry['people_balance_counts'] = $personCounts;

        return [$members, $this->telemetry];
    }

    /**
     * @param array<string, int|float|bool> $overrides
     */
    private function cloneOptions(VacationSelectionOptions $source, array $overrides): VacationSelectionOptions
    {
        return new VacationSelectionOptions(
            targetTotal: $overrides['targetTotal'] ?? $source->targetTotal,
            maxPerDay: $overrides['maxPerDay'] ?? $source->maxPerDay,
            timeSlotHours: $overrides['timeSlotHours'] ?? $source->timeSlotHours,
            minSpacingSeconds: $overrides['minSpacingSeconds'] ?? $source->minSpacingSeconds,
            phashMinHamming: $overrides['phashMinHamming'] ?? $source->phashMinHamming,
            maxPerStaypoint: $overrides['maxPerStaypoint'] ?? $source->maxPerStaypoint,
            videoBonus: $overrides['videoBonus'] ?? $source->videoBonus,
            faceBonus: $overrides['faceBonus'] ?? $source->faceBonus,
            selfiePenalty: $overrides['selfiePenalty'] ?? $source->selfiePenalty,
            qualityFloor: $overrides['qualityFloor'] ?? $source->qualityFloor,
            enablePeopleBalance: $overrides['enablePeopleBalance'] ?? $source->enablePeopleBalance,
            peopleBalanceWeight: $overrides['peopleBalanceWeight'] ?? $source->peopleBalanceWeight,
            repeatPenalty: $overrides['repeatPenalty'] ?? $source->repeatPenalty,
            faceDetectionAvailable: $overrides['faceDetectionAvailable'] ?? $source->faceDetectionAvailable,
            coreDayBonus: $overrides['coreDayBonus'] ?? $source->coreDayBonus,
            peripheralDayPenalty: $overrides['peripheralDayPenalty'] ?? $source->peripheralDayPenalty,
            phashPercentile: $overrides['phashPercentile'] ?? $source->phashPercentile,
            spacingProgressFactor: $overrides['spacingProgressFactor'] ?? $source->spacingProgressFactor,
            cohortRepeatPenalty: $overrides['cohortRepeatPenalty'] ?? $source->cohortRepeatPenalty,
            minimumTotal: $overrides['minimumTotal'] ?? $source->minimumTotal,
        );
    }

    /**
     * @param array<string, DaySummary> $daySummaries
     *
     * @return array{unique: array<string, list<Candidate>>, fallback: array<string, list<Candidate>>}
     */
    private function prefilter(array $daySummaries, VacationSelectionOptions $options): array
    {
        /** @var array<string, list<Candidate>> $unique */
        $unique = [];
        /** @var array<string, list<Candidate>> $fallback */
        $fallback = [];

        foreach ($daySummaries as $date => $summary) {
            $unique[$date]   = [];
            $fallback[$date] = [];

            /** @var list<array{media: Media, timestamp: int, burstId: string|null, order: int}> $accepted */
            $accepted = [];
            $order    = 0;

            foreach ($summary['members'] as $media) {
                ++$this->telemetry['prefilter_total'];

                if ($media->isNoShow()) {
                    ++$this->telemetry['prefilter_no_show'];

                    continue;
                }

                if ($media->isLowQuality()) {
                    ++$this->telemetry['prefilter_low_quality'];

                    continue;
                }

                if ($media->getQualityScore() === null) {
                    $this->qualityAggregator->aggregate($media);
                }

                $qualityScore = $media->getQualityScore();
                if ($qualityScore !== null && $qualityScore < $options->qualityFloor) {
                    ++$this->telemetry['prefilter_quality_floor'];

                    continue;
                }

                $burstUuid = $media->getBurstUuid();
                $accepted[] = [
                    'media'     => $media,
                    'timestamp' => $this->resolveTimestamp($media),
                    'burstId'   => $burstUuid !== null && $burstUuid !== '' ? $burstUuid : null,
                    'order'     => $order,
                ];

                ++$order;
            }

            if ($accepted === []) {
                continue;
            }

            usort(
                $accepted,
                static function (array $left, array $right): int {
                    if ($left['timestamp'] === $right['timestamp']) {
                        return $left['order'] <=> $right['order'];
                    }

                    return $left['timestamp'] <=> $right['timestamp'];
                },
            );

            /** @var array<string, array{members: list<Media>, timestamp: int, sequence: int}> $burstGroups */
            $burstGroups = [];
            /**
             * @var list<array{members: list<Media>, timestamp: int, sequence: int, index: int}> $syntheticGroups
             */
            $syntheticGroups = [];
            /** @var array{members: list<Media>, firstTimestamp: int, lastTimestamp: int}|null $currentSynthetic */
            $currentSynthetic = null;
            $nextSequence     = 0;
            $syntheticIndex   = 0;

            $finalizeSynthetic = static function () use (&$syntheticGroups, &$currentSynthetic, &$nextSequence, &$syntheticIndex): void {
                if ($currentSynthetic === null) {
                    return;
                }

                $syntheticGroups[] = [
                    'members'   => $currentSynthetic['members'],
                    'timestamp' => $currentSynthetic['firstTimestamp'],
                    'sequence'  => $nextSequence,
                    'index'     => $syntheticIndex,
                ];

                ++$nextSequence;
                ++$syntheticIndex;
                $currentSynthetic = null;
            };

            foreach ($accepted as $entry) {
                $burstId = $entry['burstId'];
                if ($burstId !== null) {
                    $finalizeSynthetic();

                    if (!array_key_exists($burstId, $burstGroups)) {
                        $burstGroups[$burstId] = [
                            'members'   => [],
                            'timestamp' => $entry['timestamp'],
                            'sequence'  => $nextSequence,
                        ];

                        ++$nextSequence;
                    }

                    $burstGroups[$burstId]['members'][] = $entry['media'];
                    if ($entry['timestamp'] < $burstGroups[$burstId]['timestamp']) {
                        $burstGroups[$burstId]['timestamp'] = $entry['timestamp'];
                    }

                    continue;
                }

                if ($currentSynthetic === null) {
                    $currentSynthetic = [
                        'members'        => [$entry['media']],
                        'firstTimestamp' => $entry['timestamp'],
                        'lastTimestamp'  => $entry['timestamp'],
                    ];

                    continue;
                }

                $delta = $entry['timestamp'] - $currentSynthetic['lastTimestamp'];
                if ($delta <= 30) {
                    $currentSynthetic['members'][] = $entry['media'];
                    $currentSynthetic['lastTimestamp'] = $entry['timestamp'];

                    continue;
                }

                $finalizeSynthetic();
                $currentSynthetic = [
                    'members'        => [$entry['media']],
                    'firstTimestamp' => $entry['timestamp'],
                    'lastTimestamp'  => $entry['timestamp'],
                ];
            }

            $finalizeSynthetic();

            /**
             * @var list<array{
             *     id: string|null,
             *     members: list<Media>,
             *     timestamp: int,
             *     sequence: int,
             * }> $groups
             */
            $groups = [];

            foreach ($burstGroups as $burstId => $group) {
                $groups[] = [
                    'id'        => $burstId,
                    'members'   => $group['members'],
                    'timestamp' => $group['timestamp'],
                    'sequence'  => $group['sequence'],
                ];
            }

            foreach ($syntheticGroups as $group) {
                $members = $group['members'];
                $groups[] = [
                    'id'        => count($members) > 1 ? sprintf('synthetic:%s:%d', $date, $group['index']) : null,
                    'members'   => $members,
                    'timestamp' => $group['timestamp'],
                    'sequence'  => $group['sequence'],
                ];
            }

            usort(
                $groups,
                static function (array $left, array $right): int {
                    if ($left['timestamp'] === $right['timestamp']) {
                        return $left['sequence'] <=> $right['sequence'];
                    }

                    return $left['timestamp'] <=> $right['timestamp'];
                },
            );

            foreach ($groups as $group) {
                $members = $group['members'];
                if ($members === []) {
                    continue;
                }

                if (count($members) === 1) {
                    $unique[$date][] = $this->createCandidate($members[0], $date, $summary, $options, 'slot');

                    continue;
                }

                $burstId        = $group['id'];
                $representative = $this->selectBurstRepresentative($members);
                $unique[$date][] = $this->createCandidate($representative, $date, $summary, $options, 'slot', $burstId);

                foreach ($members as $member) {
                    if ($member === $representative) {
                        continue;
                    }

                    $fallback[$date][] = $this->createCandidate($member, $date, $summary, $options, 'burst', $burstId);
                    ++$this->telemetry['burst_collapsed'];
                }
            }

            [$unique[$date], $extraFallback] = $this->consolidateSlots($unique[$date]);
            if ($extraFallback !== []) {
                $fallback[$date] = array_merge($fallback[$date], $extraFallback);
            }
        }

        return ['unique' => $unique, 'fallback' => $fallback];
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return array{0: list<Candidate>, 1: list<Candidate>}
     */
    private function consolidateSlots(array $candidates): array
    {
        $slots        = [];
        $slotFallback = [];

        foreach ($candidates as $candidate) {
            $slot = $candidate['slot'];
            if (!array_key_exists($slot, $slots)) {
                $slots[$slot] = $candidate;

                continue;
            }

            $existing = $slots[$slot];
            if ($candidate['score'] > $existing['score']) {
                $slotFallback[] = $existing;
                $slots[$slot]   = $candidate;

                continue;
            }

            $slotFallback[] = $candidate;
        }

        return [array_values($slots), $slotFallback];
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return list<Candidate>
     */
    private function sortPrimary(array $candidates): array
    {
        usort(
            $candidates,
            function (array $a, array $b): int {
                if ($a['slot'] !== $b['slot']) {
                    return $a['slot'] <=> $b['slot'];
                }

                if ($a['score'] !== $b['score']) {
                    return $b['score'] <=> $a['score'];
                }

                return $a['timestamp'] <=> $b['timestamp'];
            }
        );

        return array_values($candidates);
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return list<Candidate>
     */
    private function sortFallback(array $candidates): array
    {
        usort(
            $candidates,
            function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) {
                    return $b['score'] <=> $a['score'];
                }

                return $a['timestamp'] <=> $b['timestamp'];
            }
        );

        return array_values($candidates);
    }

    /**
     * @param array<string, list<Candidate>> $candidatesByDay
     * @param list<string>                    $dayOrder
     *
     * @return list<Candidate>
     */
    private function roundRobin(array $candidatesByDay, array $dayOrder): array
    {
        $ordered = [];
        $index   = 0;
        while (true) {
            $progress = false;
            foreach ($dayOrder as $day) {
                if (!isset($candidatesByDay[$day][$index])) {
                    continue;
                }

                $ordered[] = $candidatesByDay[$day][$index];
                $progress  = true;
            }

            if (!$progress) {
                break;
            }

            ++$index;
        }

        return $ordered;
    }

    /**
     * @param array<string, DaySummary> $daySummaries
     * @param list<string>              $dayOrder
     *
     * @return array<string, int>
     */
    private function buildDayCaps(array $daySummaries, array $dayOrder, VacationSelectionOptions $options, int $basePerDayCap): array
    {
        $caps     = [];
        $spacing  = [];
        $fallback = max(0, $options->minSpacingSeconds);

        foreach ($dayOrder as $day) {
            $category = $this->resolveDayCategory($daySummaries[$day] ?? null);
            $cap      = $basePerDayCap;

            if ($category === 'core') {
                $cap += $options->coreDayBonus;
            } else {
                $cap -= $options->peripheralDayPenalty;
            }

            $perDayCap     = max(1, min($options->maxPerDay, $cap));
            $caps[$day]    = $perDayCap;
            $spacing[$day] = $this->resolveDaySpacingThreshold($daySummaries[$day] ?? null, $perDayCap, $fallback);
        }

        $this->daySpacingThresholds = $spacing;

        return $caps;
    }

    /**
     * @param DaySummary|array<string, mixed>|null $summary
     */
    private function resolveDaySpacingThreshold($summary, int $perDayCap, int $profileMinSpacing): int
    {
        $baseline = max(0, $profileMinSpacing);

        if (!is_array($summary)) {
            return $baseline;
        }

        $context  = $summary['selectionContext'] ?? null;
        $duration = null;

        if (is_array($context)) {
            $candidate = $context['duration'] ?? null;
            if (is_int($candidate)) {
                $duration = $candidate;
            } elseif (is_string($candidate) && is_numeric($candidate)) {
                $duration = (int) $candidate;
            }
        }

        if ($duration === null || $duration <= 0) {
            return $baseline;
        }

        $slots    = max(3, $perDayCap + 1);
        $adaptive = (int) ceil($duration / $slots);

        if ($adaptive < $baseline) {
            return $baseline;
        }

        return $adaptive;
    }

    /**
     * @param array<string, DaySummary> $daySummaries
     * @param list<string>              $dayOrder
     *
     * @return array<string, string>
     */
    private function collectDayCategories(array $daySummaries, array $dayOrder): array
    {
        $categories = [];
        foreach ($dayOrder as $day) {
            $categories[$day] = $this->resolveDayCategory($daySummaries[$day] ?? null);
        }

        return $categories;
    }

    /**
     * @param DaySummary|array<string, mixed>|null $summary
     */
    private function resolveDayCategory($summary): string
    {
        if (!is_array($summary)) {
            return 'peripheral';
        }

        $context = $summary['selectionContext'] ?? null;
        if (is_array($context)) {
            $candidate = $context['category'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'peripheral';
    }

    private function resolveStaypointCap(VacationSelectionOptions $options, int $basePerDayCap): int
    {
        $half = (int) floor($basePerDayCap / 2);
        if ($half < 1) {
            $half = 1;
        }

        $cap = min($options->maxPerStaypoint, $half);
        if ($cap < 1) {
            return 1;
        }

        return $cap;
    }

    /**
     * @param array<string, list<Candidate>> $primaryByDay
     * @param array<string, list<Candidate>> $fallbackByDay
     *
     * @return list<int>
     */
    private function samplePhashDistances(array $primaryByDay, array $fallbackByDay, VacationSelectionOptions $options): array
    {
        $pool = [];

        foreach ($primaryByDay as $candidates) {
            foreach ($candidates as $candidate) {
                $pool[] = $candidate;
            }
        }

        foreach ($fallbackByDay as $candidates) {
            foreach ($candidates as $candidate) {
                $pool[] = $candidate;
            }
        }

        $total = count($pool);
        if ($total < 2) {
            return [];
        }

        usort(
            $pool,
            static function (array $left, array $right): int {
                return $left['timestamp'] <=> $right['timestamp'];
            }
        );

        $samples = [];
        for ($i = 0; $i < $total - 1; ++$i) {
            $limit = min($total - 1, $i + 5);
            for ($j = $i + 1; $j <= $limit; ++$j) {
                $seconds = $this->metrics->secondsBetween($pool[$i]['media'], $pool[$j]['media']);
                $window  = max(600, $options->minSpacingSeconds);
                if ($seconds > $window) {
                    continue;
                }

                $distance = $this->metrics->phashDistance($pool[$i]['media'], $pool[$j]['media']);
                if ($distance !== null) {
                    $samples[] = $distance;
                }
            }
        }

        return $samples;
    }

    /**
     * @param list<int> $samples
     */
    private function resolvePhashPercentile(array $samples, float $ratio): int
    {
        if ($samples === []) {
            return 0;
        }

        $sorted = $samples;
        sort($sorted);
        $count   = count($sorted);
        $clamped = max(0.0, min(1.0, $ratio));
        $index   = (int) floor($clamped * ($count - 1));
        $value   = $sorted[$index];

        return (int) ceil((float) $value);
    }

    /**
     * @param list<Candidate>            $selected
     * @param array<string, int>         $dayCounts
     * @param array<string, int>         $staypointCounts
     * @param array<string, int>         $personCounts
     */
    private function considerCandidate(
        array $candidate,
        array &$selected,
        array &$dayCounts,
        array &$staypointCounts,
        array &$personCounts,
        VacationSelectionOptions $options,
        bool $fromFallback = false,
    ): bool {
        $day      = $candidate['day'];
        $dayCount = $dayCounts[$day] ?? 0;
        $dayCap   = $this->dayCaps[$day] ?? $this->basePerDayCap;
        if ($dayCount >= $dayCap) {
            ++$this->telemetry['day_limit_rejections'];

            return false;
        }

        $duplicateIndex = $this->findDuplicate($candidate, $selected, $options);
        if ($duplicateIndex !== null) {
            $existing = $selected[$duplicateIndex];
            if ($candidate['quality'] > $existing['quality']) {
                $this->replaceSelection($duplicateIndex, $candidate, $selected, $dayCounts, $staypointCounts, $personCounts);
                ++$this->telemetry['near_duplicate_replacements'];
                if ($fromFallback) {
                    ++$this->telemetry['fallback_used'];
                }

                return true;
            }

            ++$this->telemetry['near_duplicate_blocked'];

            return false;
        }

        $staypointKey = $candidate['staypointKey'];
        if ($staypointKey !== null) {
            $currentStaypointCount = $staypointCounts[$staypointKey] ?? 0;
            if ($currentStaypointCount >= $this->effectiveMaxPerStaypoint) {
                ++$this->telemetry['staypoint_rejections'];

                return false;
            }
        }

        $candidateSpacing = $this->daySpacingThresholds[$day] ?? $options->minSpacingSeconds;

        foreach ($selected as $existing) {
            $seconds          = $this->metrics->secondsBetween($candidate['media'], $existing['media']);
            $existingSpacing  = $this->daySpacingThresholds[$existing['day']] ?? $options->minSpacingSeconds;
            $pairSpacing      = max($options->minSpacingSeconds, $candidateSpacing, $existingSpacing);

            if ($seconds < $pairSpacing) {
                ++$this->telemetry['spacing_rejections'];

                return false;
            }
        }

        $balanced = $this->applyPeopleBalancing($candidate, $selected, $personCounts, $options);
        if ($balanced === null) {
            return false;
        }

        $candidate = $balanced;

        $selected[] = $candidate;
        $dayCounts[$day] = $dayCount + 1;
        if ($staypointKey !== null) {
            $staypointCounts[$staypointKey] = ($staypointCounts[$staypointKey] ?? 0) + 1;
        }

        $this->incrementPersonCounts($candidate, $personCounts);

        if ($fromFallback) {
            ++$this->telemetry['fallback_used'];
        }

        return true;
    }

    /**
     * @param list<Candidate>    $selected
     */
    private function replaceSelection(
        int $index,
        array $candidate,
        array &$selected,
        array &$dayCounts,
        array &$staypointCounts,
        array &$personCounts,
    ): void {
        $removed = $selected[$index];
        $removedDay = $removed['day'];
        $dayCounts[$removedDay] = max(0, ($dayCounts[$removedDay] ?? 1) - 1);
        if ($removed['staypointKey'] !== null) {
            $key = $removed['staypointKey'];
            $staypointCounts[$key] = max(0, ($staypointCounts[$key] ?? 1) - 1);
        }

        $this->decrementPersonCounts($removed, $personCounts);

        $selected[$index] = $candidate;
        $dayCounts[$candidate['day']] = ($dayCounts[$candidate['day']] ?? 0) + 1;
        if ($candidate['staypointKey'] !== null) {
            $key = $candidate['staypointKey'];
            $staypointCounts[$key] = ($staypointCounts[$key] ?? 0) + 1;
        }

        $this->incrementPersonCounts($candidate, $personCounts);
    }

    /**
     * @param list<Candidate>          $selected
     * @param array<string, int>       $personCounts
     */
    private function applyPeopleBalancing(
        array $candidate,
        array $selected,
        array $personCounts,
        VacationSelectionOptions $options,
    ): ?array {
        if ($options->enablePeopleBalance === false) {
            return $candidate;
        }

        /** @var list<string> $persons */
        $persons = $candidate['persons'] ?? [];
        if ($persons === []) {
            return $candidate;
        }

        ++$this->telemetry['people_balance_considered'];

        $weight = $options->peopleBalanceWeight;
        if ($weight < 0.0) {
            $weight = 0.0;
        } elseif ($weight > 1.0) {
            $weight = 1.0;
        }

        $nextIndex = count($selected) + 1;
        $limit     = $weight <= 0.0 ? 1 : (int) ceil($nextIndex * $weight);
        if ($limit < 1) {
            $limit = 1;
        }

        $dominantCount = 0;
        foreach ($persons as $person) {
            $current = $personCounts[$person] ?? 0;
            if ($current > $dominantCount) {
                $dominantCount = $current;
            }
        }

        $projectedCount = $dominantCount + 1;
        if ($projectedCount > $limit) {
            ++$this->telemetry['people_balance_rejected'];

            return null;
        }

        $maxOverlap = 0.0;
        foreach ($selected as $existing) {
            $overlap = $this->metrics->personOverlap($candidate['media'], $existing['media']);
            if ($overlap > $maxOverlap) {
                $maxOverlap = $overlap;
            }
        }

        $shareRatio = 0.0;
        if ($limit > 0) {
            $shareRatio = $dominantCount / (float) $limit;
            if ($shareRatio > 1.0) {
                $shareRatio = 1.0;
            }
        }

        $overlapFactor = max($maxOverlap, $shareRatio);

        if ($options->repeatPenalty !== 0.0 && $overlapFactor > 0.0) {
            $adjustment = -$options->repeatPenalty * $overlapFactor;
            if ($adjustment < 0.0) {
                ++$this->telemetry['people_balance_penalized'];
            } elseif ($adjustment > 0.0) {
                ++$this->telemetry['people_balance_bonuses'];
            }

            $candidate['score'] = $this->scoreMedia(
                $candidate['media'],
                $options,
                $candidate['quality'],
                $adjustment,
            );

            if ($candidate['score'] <= 0.0) {
                ++$this->telemetry['people_balance_rejected'];

                return null;
            }
        }

        ++$this->telemetry['people_balance_accepted'];

        return $candidate;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, int>   $personCounts
     */
    private function incrementPersonCounts(array $candidate, array &$personCounts): void
    {
        /** @var list<string> $persons */
        $persons = $candidate['persons'] ?? [];
        if ($persons === []) {
            return;
        }

        foreach ($persons as $person) {
            $personCounts[$person] = ($personCounts[$person] ?? 0) + 1;
        }
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, int>   $personCounts
     */
    private function decrementPersonCounts(array $candidate, array &$personCounts): void
    {
        /** @var list<string> $persons */
        $persons = $candidate['persons'] ?? [];
        if ($persons === []) {
            return;
        }

        foreach ($persons as $person) {
            $current = $personCounts[$person] ?? 0;
            if ($current <= 1) {
                unset($personCounts[$person]);

                continue;
            }

            $personCounts[$person] = $current - 1;
        }
    }

    /**
     * @param list<Candidate> $selected
     */
    private function findDuplicate(array $candidate, array $selected, VacationSelectionOptions $options): ?int
    {
        foreach ($selected as $index => $existing) {
            if ($candidate['burstId'] !== null && $candidate['burstId'] === $existing['burstId']) {
                return $index;
            }

            if (!$this->metrics->shareSameDevice($candidate['media'], $existing['media'])) {
                continue;
            }

            $distance = $this->metrics->phashDistance($candidate['media'], $existing['media']);
            if ($distance !== null && $distance <= $this->effectivePhashMin) {
                return $index;
            }

            $seconds = $this->metrics->secondsBetween($candidate['media'], $existing['media']);
            if ($seconds <= max(300, $options->minSpacingSeconds)) {
                return $index;
            }
        }

        return null;
    }

    private function compareCandidates(array $a, array $b): int
    {
        if ($a['timestamp'] !== $b['timestamp']) {
            return $a['timestamp'] <=> $b['timestamp'];
        }

        if ($a['quality'] !== $b['quality']) {
            return $b['quality'] <=> $a['quality'];
        }

        return $a['media']->getChecksum() <=> $b['media']->getChecksum();
    }

    /**
     * @param list<Media> $members
     */
    private function selectBurstRepresentative(array $members): Media
    {
        $best = $members[0];
        foreach ($members as $member) {
            if ($member->isBurstRepresentative() === true) {
                $best = $member;

                break;
            }
        }

        foreach ($members as $member) {
            if ($member->getQualityScore() !== null && $best->getQualityScore() !== null) {
                if ($member->getQualityScore() > $best->getQualityScore()) {
                    $best = $member;
                }
            }
        }

        return $best;
    }

    private function createCandidate(
        Media $media,
        string $date,
        array $summary,
        VacationSelectionOptions $options,
        string $origin,
        ?string $burstId = null,
    ): array {
        $timestamp = $this->resolveTimestamp($media);
        $slot      = $this->computeSlot($media, $summary, $options, $timestamp);
        $quality   = $media->getQualityScore() ?? 0.5;
        $score     = $this->scoreMedia($media, $options, $quality);
        $staypoint = null;
        $index     = $summary['staypointIndex'] ?? null;
        if ($index instanceof StaypointIndex) {
            $staypoint = $index->get($media);
        } else {
            $staypoint = $this->staypointKey($timestamp, $summary, $date);
        }
        $persons   = $media->getPersons();
        $normalizedPersons = $persons !== null ? array_values(array_unique($persons)) : [];

        return [
            'media'       => $media,
            'day'         => $date,
            'summary'     => $summary,
            'timestamp'   => $timestamp,
            'slot'        => $slot,
            'score'       => $score,
            'quality'     => $quality,
            'staypointKey'=> $staypoint,
            'burstId'     => $burstId,
            'origin'      => $origin,
            'persons'     => $normalizedPersons,
        ];
    }

    private function computeSlot(Media $media, array $summary, VacationSelectionOptions $options, int $timestamp): int
    {
        $timezoneIdentifier = $summary['localTimezoneIdentifier'] ?? 'UTC';
        try {
            $timezone = new DateTimeZone($timezoneIdentifier);
        } catch (\Throwable) {
            $timezone = new DateTimeZone('UTC');
        }

        $time     = $media->getTakenAt() ?? new DateTimeImmutable('@' . $timestamp);
        $local    = $time->setTimezone($timezone);
        $hour     = (int) $local->format('H');
        $slotSize = max(1, $options->timeSlotHours);

        return intdiv($hour, $slotSize);
    }

    private function scoreMedia(
        Media $media,
        VacationSelectionOptions $options,
        float $quality,
        float $peopleAdjustment = 0.0,
    ): float
    {
        $score = $quality;

        if ($media->isVideo()) {
            $score += $options->videoBonus;
        }

        if ($media->hasFaces()) {
            $score += $options->faceBonus;
        }

        if ($media->getFacesCount() === 1) {
            $score -= $options->selfiePenalty;
        }

        if ($peopleAdjustment !== 0.0) {
            $score += $peopleAdjustment;
        }

        if ($score < 0.0) {
            return 0.0;
        }

        return $score;
    }

    private function staypointKey(int $timestamp, array $summary, string $date): ?string
    {
        foreach ($summary['staypoints'] as $staypoint) {
            if ($timestamp >= (int) $staypoint['start'] && $timestamp <= (int) $staypoint['end']) {
                return sprintf('%s:%d:%d', $date, (int) $staypoint['start'], (int) $staypoint['end']);
            }
        }

        return null;
    }

    private function resolveTimestamp(Media $media): int
    {
        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            return $takenAt->getTimestamp();
        }

        return $media->getCreatedAt()->getTimestamp();
    }
}
