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
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Quality\MediaQualityAggregator;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
use function intdiv;
use function max;
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
        $this->telemetry = [
            'prefilter_total'            => 0,
            'prefilter_no_show'          => 0,
            'prefilter_low_quality'      => 0,
            'prefilter_quality_floor'    => 0,
            'burst_collapsed'            => 0,
            'day_limit_rejections'       => 0,
            'staypoint_rejections'       => 0,
            'spacing_rejections'         => 0,
            'near_duplicate_blocked'     => 0,
            'near_duplicate_replacements'=> 0,
            'fallback_used'              => 0,
        ];

        if ($daySummaries === []) {
            return new SelectionResult([], $this->telemetry);
        }

        $filtered = $this->prefilter($daySummaries, $options);
        if ($filtered['unique'] === [] && $filtered['fallback'] === []) {
            return new SelectionResult([], $this->telemetry);
        }

        $primaryByDay  = $filtered['unique'];
        $fallbackByDay = $filtered['fallback'];

        $dayOrder = array_keys($primaryByDay + $fallbackByDay);
        sort($dayOrder);

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

        foreach ($primaryOrder as $candidate) {
            if ($this->considerCandidate($candidate, $selected, $dayCounts, $staypointCounts, $options)) {
                if (count($selected) >= $options->targetTotal) {
                    break;
                }
            }
        }

        if (count($selected) < $options->targetTotal) {
            foreach ($fallbackOrder as $candidate) {
                if ($this->considerCandidate($candidate, $selected, $dayCounts, $staypointCounts, $options, true)) {
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

        $this->telemetry['selected_total'] = count($members);

        return new SelectionResult($members, $this->telemetry);
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

            $groups = [];
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

                $burstId = $media->getBurstUuid();
                if ($burstId === null || $burstId === '') {
                    $unique[$date][] = $this->createCandidate($media, $date, $summary, $options, 'slot');

                    continue;
                }

                $groups[$burstId] ??= [];
                $groups[$burstId][] = $media;
            }

            foreach ($groups as $burstId => $members) {
                if (count($members) === 1) {
                    $unique[$date][] = $this->createCandidate($members[0], $date, $summary, $options, 'slot');

                    continue;
                }

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
     * @param list<Candidate>            $selected
     * @param array<string, int>         $dayCounts
     * @param array<string, int>         $staypointCounts
     */
    private function considerCandidate(
        array $candidate,
        array &$selected,
        array &$dayCounts,
        array &$staypointCounts,
        VacationSelectionOptions $options,
        bool $fromFallback = false,
    ): bool {
        $day      = $candidate['day'];
        $dayCount = $dayCounts[$day] ?? 0;
        if ($dayCount >= $options->maxPerDay) {
            ++$this->telemetry['day_limit_rejections'];

            return false;
        }

        $duplicateIndex = $this->findDuplicate($candidate, $selected, $options);
        if ($duplicateIndex !== null) {
            $existing = $selected[$duplicateIndex];
            if ($candidate['quality'] > $existing['quality']) {
                $this->replaceSelection($duplicateIndex, $candidate, $selected, $dayCounts, $staypointCounts);
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
            if ($currentStaypointCount >= $options->maxPerStaypoint) {
                ++$this->telemetry['staypoint_rejections'];

                return false;
            }
        }

        foreach ($selected as $existing) {
            $seconds = $this->metrics->secondsBetween($candidate['media'], $existing['media']);
            if ($seconds < $options->minSpacingSeconds) {
                ++$this->telemetry['spacing_rejections'];

                return false;
            }
        }

        $selected[] = $candidate;
        $dayCounts[$day] = $dayCount + 1;
        if ($staypointKey !== null) {
            $staypointCounts[$staypointKey] = ($staypointCounts[$staypointKey] ?? 0) + 1;
        }

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
    ): void {
        $removed = $selected[$index];
        $removedDay = $removed['day'];
        $dayCounts[$removedDay] = max(0, ($dayCounts[$removedDay] ?? 1) - 1);
        if ($removed['staypointKey'] !== null) {
            $key = $removed['staypointKey'];
            $staypointCounts[$key] = max(0, ($staypointCounts[$key] ?? 1) - 1);
        }

        $selected[$index] = $candidate;
        $dayCounts[$candidate['day']] = ($dayCounts[$candidate['day']] ?? 0) + 1;
        if ($candidate['staypointKey'] !== null) {
            $key = $candidate['staypointKey'];
            $staypointCounts[$key] = ($staypointCounts[$key] ?? 0) + 1;
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
            if ($distance !== null && $distance <= $options->phashMinHamming) {
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
        $staypoint = $this->staypointKey($timestamp, $summary, $date);

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

    private function scoreMedia(Media $media, VacationSelectionOptions $options, float $quality): float
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
