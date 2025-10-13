<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Contract\StaypointDetectorInterface;
use MagicSunday\Memories\Clusterer\Selection\MemberSelectorInterface;
use MagicSunday\Memories\Clusterer\Selection\SelectionResult;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterMemberSelectionServiceInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\ClusterMemberSelectionProfile;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use MagicSunday\Memories\Service\Monitoring\PhaseMetricsCollector;
use MagicSunday\Memories\Utility\Phash;
use Throwable;

use function array_key_exists;
use function array_map;
use function array_sum;
use function count;
use function gmdate;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function spl_object_id;
use function usort;

/**
 * Applies the curated member selection to raw cluster drafts.
 */
final class ClusterMemberSelectionService implements ClusterMemberSelectionServiceInterface
{
    /** @var array<int, int> */
    private array $timestampCache = [];

    /** @var array<int, string|null> */
    private array $phashCache = [];

    /** @var array<int, string> */
    private array $dayIndex = [];

    /** @var array<string, array<string, mixed>> */
    private array $daySummaries = [];

    public function __construct(
        private readonly MemberSelectorInterface $memberSelector,
        private readonly MemberMediaLookupInterface $mediaLookup,
        private readonly ClusterMemberSelectionProfileProvider $profileProvider,
        private readonly StaypointDetectorInterface $staypointDetector,
        private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
    }

    public function curate(ClusterDraft $draft): ClusterDraft
    {
        $members = $draft->getMembers();
        if ($members === []) {
            return $draft;
        }

        $phaseMetrics = $this->monitoringEmitter !== null ? new PhaseMetricsCollector() : null;
        $initialCount = count($members);

        $this->resetCaches();

        $phaseMetrics?->begin('filtering');
        $phaseMetrics?->addCounts('filtering', 'members', ['input' => $initialCount]);

        $media = $this->loadMedia($members);
        $phaseMetrics?->addCounts('filtering', 'members', ['loaded' => count($media)]);
        $phaseMetrics?->end('filtering');

        if ($media === []) {
            $this->emitPhaseSummary($draft, $phaseMetrics, 'skipped', [
                'reason'       => 'media_lookup_empty',
                'members_pre'  => $initialCount,
                'members_post' => 0,
            ]);

            return $draft;
        }

        $profile      = $this->profileProvider->resolve($draft);

        $phaseMetrics?->begin('summarising');
        $daySummaries = $this->buildDaySummaries($media);
        if ($phaseMetrics !== null) {
            $this->recordSummaryMetrics($daySummaries, $phaseMetrics);
        }
        $phaseMetrics?->end('summarising');

        if ($daySummaries === []) {
            $this->emitPhaseSummary($draft, $phaseMetrics, 'skipped', [
                'reason'       => 'day_summary_empty',
                'members_pre'  => $initialCount,
                'members_post' => 0,
                'profile'      => $profile->getKey(),
            ]);

            return $draft;
        }

        $this->daySummaries = $daySummaries;
        $phaseMetrics?->begin('selecting');
        $result = $this->memberSelector->select($daySummaries, $profile->getHome(), $profile->getOptions());
        if ($phaseMetrics !== null) {
            $this->recordSelectionMetrics(
                $result->getTelemetry(),
                $phaseMetrics,
                count($result->getMembers()),
                count($media),
            );
        }
        $phaseMetrics?->end('selecting');

        $phaseMetrics?->begin('consolidating');
        $updated = $this->applySelection($draft, $media, $result, $profile, $phaseMetrics);
        $phaseMetrics?->end('consolidating');

        $this->emitPhaseSummary($updated, $phaseMetrics, 'completed', [
            'members_pre'  => $initialCount,
            'members_post' => $updated->getMembersCount(),
            'profile'      => $profile->getKey(),
        ]);

        return $updated;
    }

    /**
     * @param list<int> $memberIds
     *
     * @return list<Media>
     */
    private function loadMedia(array $memberIds): array
    {
        $media = $this->mediaLookup->findByIds($memberIds);
        if ($media === []) {
            return [];
        }

        return $media;
    }

    /**
     * @param list<Media> $media
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildDaySummaries(array $media): array
    {
        $summaries = [];

        foreach ($media as $item) {
            $timestamp = $this->resolveTimestamp($item);
            $date      = gmdate('Y-m-d', $timestamp);

            if (!array_key_exists($date, $summaries)) {
                $summaries[$date] = $this->createSummarySkeleton($date, $item, $timestamp);
            }

            $summaries[$date]['members'][] = $item;

            if ($item->getGpsLat() !== null && $item->getGpsLon() !== null) {
                $summaries[$date]['gpsMembers'][] = $item;
                if ($summaries[$date]['firstGpsMedia'] === null) {
                    $summaries[$date]['firstGpsMedia'] = $item;
                }

                $summaries[$date]['lastGpsMedia'] = $item;
            }

            if ($item->isVideo()) {
                $summaries[$date]['videoCount'] = ($summaries[$date]['videoCount'] ?? 0) + 1;
            } else {
                $summaries[$date]['photoCount'] = ($summaries[$date]['photoCount'] ?? 0) + 1;
            }

            $this->dayIndex[spl_object_id($item)] = $date;
        }

        foreach ($summaries as &$summary) {
            if ($summary['gpsMembers'] === []) {
                continue;
            }

            usort(
                $summary['gpsMembers'],
                fn (Media $left, Media $right): int => $this->resolveTimestamp($left) <=> $this->resolveTimestamp($right),
            );

            $summary['staypoints'] = $this->staypointDetector->detect($summary['gpsMembers']);
        }
        unset($summary);

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    private function createSummarySkeleton(string $date, Media $media, int $timestamp): array
    {
        $takenAt = $media->getTakenAt();
        $timezoneIdentifier = 'UTC';
        $weekday            = (int) gmdate('N', $timestamp);

        if ($takenAt instanceof DateTimeImmutable) {
            $timezoneIdentifier = $takenAt->getTimezone()->getName();
            $weekday            = (int) $takenAt->format('N');
        }

        return [
            'date'                   => $date,
            'members'                => [],
            'gpsMembers'             => [],
            'maxDistanceKm'          => 0.0,
            'avgDistanceKm'          => 0.0,
            'travelKm'               => 0.0,
            'maxSpeedKmh'            => 0.0,
            'avgSpeedKmh'            => 0.0,
            'hasHighSpeedTransit'    => false,
            'countryCodes'           => [],
            'timezoneOffsets'        => [],
            'localTimezoneIdentifier'=> $timezoneIdentifier,
            'localTimezoneOffset'    => null,
            'tourismHits'            => 0,
            'poiSamples'             => 0,
            'tourismRatio'           => 0.0,
            'hasAirportPoi'          => false,
            'weekday'                => $weekday,
            'photoCount'             => 0,
            'videoCount'             => 0,
            'densityZ'               => 0.0,
            'isAwayCandidate'        => false,
            'sufficientSamples'      => true,
            'spotClusters'           => [],
            'spotNoise'              => [],
            'spotCount'              => 0,
            'spotNoiseSamples'       => 0,
            'spotDwellSeconds'       => 0,
            'staypoints'             => [],
            'baseLocation'           => null,
            'baseAway'               => false,
            'awayByDistance'         => false,
            'firstGpsMedia'          => null,
            'lastGpsMedia'           => null,
            'isSynthetic'            => false,
        ];
    }

    private function applySelection(
        ClusterDraft $draft,
        array $media,
        SelectionResult $result,
        ClusterMemberSelectionProfile $profile,
        ?PhaseMetricsCollector $phaseMetrics = null,
    ): ClusterDraft {
        $curatedMembers = $result->getMembers();
        $preCount       = count($media);
        $postCount      = count($curatedMembers);

        if ($phaseMetrics !== null) {
            $phaseMetrics->addCounts('consolidating', 'members', [
                'pre'     => $preCount,
                'post'    => $postCount,
                'dropped' => $preCount > $postCount ? $preCount - $postCount : 0,
            ]);
        }

        $params = $draft->getParams();
        $params['member_selection'] = $this->buildSelectionMetadata(
            $profile,
            $preCount,
            $postCount,
            $result,
            $curatedMembers,
            $draft->getStoryline(),
            $phaseMetrics,
        );

        if ($curatedMembers === []) {
            return $draft->withParams($params);
        }

        $memberIds = array_map(
            static fn (Media $item): int => (int) $item->getId(),
            $curatedMembers,
        );

        $updated = $draft->withMembers($memberIds, $params);

        $photoCount = 0;
        $videoCount = 0;
        foreach ($curatedMembers as $item) {
            if ($item->isVideo()) {
                ++$videoCount;
            } else {
                ++$photoCount;
            }
        }

        if ($phaseMetrics !== null) {
            $phaseMetrics->addCounts('consolidating', 'media_types', [
                'photos' => $photoCount,
                'videos' => $videoCount,
            ]);
        }

        $updated->setMembersCount($postCount);
        $updated->setPhotoCount($photoCount);
        $updated->setVideoCount($videoCount);

        return $updated;
    }

    /**
     * @param list<Media> $members
     *
     * @return array<string, mixed>
     */
    private function buildSelectionMetadata(
        ClusterMemberSelectionProfile $profile,
        int $preCount,
        int $postCount,
        SelectionResult $result,
        array $members,
        string $storyline,
        ?PhaseMetricsCollector $phaseMetrics = null,
    ): array {
        $droppedCount       = $preCount > $postCount ? $preCount - $postCount : 0;
        $spacing            = $this->computeSpacingSamples($members);
        $perDayDistribution = $this->countPerDay($members);
        $hashSamples        = $this->collectHashSamples($members);
        $telemetry          = $this->buildSelectionTelemetry(
            $profile,
            $result,
            $preCount,
            $postCount,
            $droppedCount,
            $spacing,
            $perDayDistribution,
            $hashSamples,
            $members,
            $storyline,
        );

        if ($phaseMetrics !== null) {
            $phaseMetrics->addSamples('consolidating', 'spacing_seconds', $spacing['samples']);

            $phashTelemetry = $telemetry['phash'] ?? null;
            $consecutive    = [];
            if (is_array($phashTelemetry)) {
                $values = $phashTelemetry['consecutive_hamming'] ?? null;
                if (is_array($values)) {
                    $consecutive = $values;
                }
            }

            $phaseMetrics->addSamples('consolidating', 'phash_hamming', $consecutive);
        }

        $rejections = [];
        if (isset($telemetry['rejections']) && is_array($telemetry['rejections'])) {
            $rejections = $this->normaliseCounts($telemetry['rejections']);
        }

        $spacingRejections        = (int) ($rejections[SelectionTelemetry::REASON_TIME_GAP] ?? ($telemetry['drops']['selection']['spacing_rejections'] ?? 0));
        $nearDuplicateBlocked     = (int) ($rejections[SelectionTelemetry::REASON_PHASH] ?? ($telemetry['drops']['selection']['near_duplicate_blocked'] ?? 0));
        $nearDuplicateReplacements = (int) ($telemetry['drops']['selection']['near_duplicate_replacements'] ?? 0);

        return [
            'profile'                => $profile->getKey(),
            'storyline'              => $storyline,
            'counts'                 => $telemetry['counts'],
            'spacing'                => [
                'average_seconds' => $spacing['average'],
                'samples'         => $spacing['samples'],
                'rejections'      => $spacingRejections,
            ],
            'near_duplicates'        => [
                'blocked'      => $nearDuplicateBlocked,
                'replacements' => $nearDuplicateReplacements,
            ],
            'per_day_distribution'   => $perDayDistribution,
            'per_bucket_distribution'=> $telemetry['distribution']['per_bucket'],
            'options'                => [
                'selector'            => $this->memberSelector::class,
                'target_total'        => $profile->getOptions()->targetTotal,
                'max_per_day'         => $profile->getOptions()->maxPerDay,
                'time_slot_hours'     => $profile->getOptions()->timeSlotHours,
                'min_spacing_seconds' => $profile->getOptions()->minSpacingSeconds,
                'phash_min_hamming'   => $profile->getOptions()->phashMinHamming,
                'max_per_staypoint'   => $profile->getOptions()->maxPerStaypoint,
                'video_bonus'         => $profile->getOptions()->videoBonus,
                'face_bonus'          => $profile->getOptions()->faceBonus,
                'selfie_penalty'      => $profile->getOptions()->selfiePenalty,
                'quality_floor'       => $profile->getOptions()->qualityFloor,
                'enable_people_balance' => $profile->getOptions()->enablePeopleBalance,
                'people_balance_weight' => $profile->getOptions()->peopleBalanceWeight,
                'repeat_penalty'        => $profile->getOptions()->repeatPenalty,
            ],
            'hash_samples'           => $hashSamples,
            'exclusion_reasons'      => $telemetry['rejections'] ?? [],
            'telemetry'              => $telemetry,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $daySummaries
     */
    private function recordSummaryMetrics(array $daySummaries, PhaseMetricsCollector $phaseMetrics): void
    {
        $totalDays      = count($daySummaries);
        $gpsDays        = 0;
        $staypointCount = 0;

        foreach ($daySummaries as $summary) {
            $gpsMembers = $summary['gpsMembers'] ?? null;
            if (is_array($gpsMembers) && $gpsMembers !== []) {
                ++$gpsDays;
            }

            $staypointCountValue = $summary['staypointCount'] ?? null;
            if (is_int($staypointCountValue)) {
                $staypointCount += $staypointCountValue;
                continue;
            }

            $staypoints = $summary['staypoints'] ?? null;
            if (is_array($staypoints)) {
                $staypointCount += count($staypoints);
            }
        }

        $phaseMetrics->addCounts('summarising', 'days', [
            'total'    => $totalDays,
            'with_gps' => $gpsDays,
        ]);
        $phaseMetrics->addCounts('summarising', 'staypoints', [
            'total' => $staypointCount,
        ]);
    }

    /**
     * @param array<string, mixed> $telemetry
     */
    private function recordSelectionMetrics(
        array $telemetry,
        PhaseMetricsCollector $phaseMetrics,
        int $selectedCount,
        int $eligibleFallback,
    ): void {
        $counts = [];
        $telemetryCounts = $telemetry['counts'] ?? null;
        if (is_array($telemetryCounts)) {
            $counts = $this->normaliseCounts($telemetryCounts);
        }

        if ($counts === []) {
            $counts = [
                'eligible' => $eligibleFallback,
                'selected' => $selectedCount,
            ];
        } else {
            if (!array_key_exists('eligible', $counts)) {
                $counts['eligible'] = $eligibleFallback;
            }

            $counts['selected'] = $counts['selected'] ?? $selectedCount;
        }

        $phaseMetrics->addCounts('selecting', 'members', $counts);

        $rejections = $telemetry['rejections'] ?? null;
        if (is_array($rejections)) {
            $phaseMetrics->addCounts('selecting', 'rejections', $this->normaliseCounts($rejections));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emitPhaseSummary(
        ClusterDraft $draft,
        ?PhaseMetricsCollector $phaseMetrics,
        string $status,
        array $context = [],
    ): void {
        if ($this->monitoringEmitter === null || $phaseMetrics === null) {
            return;
        }

        $payload = $phaseMetrics->summarise([
            'algorithm' => $draft->getAlgorithm(),
            'storyline' => $draft->getStoryline(),
        ] + $context);

        $this->monitoringEmitter->emit('cluster_member_selection', $status, $payload);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, int|float>
     */
    private function normaliseCounts(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || $value === null) {
                continue;
            }

            if (!is_int($value) && !is_float($value)) {
                if (!is_numeric($value)) {
                    continue;
                }

                $value = (float) $value;
            }

            $result[$key] = is_float($value) ? $value : (int) $value;
        }

        return $result;
    }

    /**
     * @param list<Media> $members
     *
     * @return array{average: float, samples: list<int>}
     */
    private function computeSpacingSamples(array $members): array
    {
        $samples          = [];
        $previousTimestamp = null;

        foreach ($members as $media) {
            $timestamp = $this->resolveTimestamp($media);
            if ($previousTimestamp !== null) {
                $samples[] = max(0, $timestamp - $previousTimestamp);
            }

            $previousTimestamp = $timestamp;
        }

        $average = 0.0;
        if ($samples !== []) {
            $average = array_sum($samples) / count($samples);
        }

        return ['average' => $average, 'samples' => $samples];
    }

    /**
     * @param list<Media> $members
     *
     * @return array<string, int>
     */
    private function countPerDay(array $members): array
    {
        $distribution = [];
        foreach ($members as $media) {
            $objectId = spl_object_id($media);
            $dayKey   = $this->dayIndex[$objectId] ?? null;

            if ($dayKey === null) {
                continue;
            }

            $distribution[$dayKey] = ($distribution[$dayKey] ?? 0) + 1;
        }

        return $distribution;
    }

    /**
     * @param list<Media> $members
     *
     * @return array<int, string|null>
     */
    private function collectHashSamples(array $members): array
    {
        $hashes = [];
        foreach ($members as $media) {
            $id      = $media->getId();
            $hashes[$id ?? spl_object_id($media)] = $this->resolvePhashHex($media);
        }

        return $hashes;
    }

    /**
     * @param ClusterMemberSelectionProfile $profile
     * @param SelectionResult               $result
     * @param int                           $preCount
     * @param int                           $postCount
     * @param int                           $droppedCount
     * @param array{average: float, samples: list<int>} $spacing
     * @param array<string, int>                     $perDayDistribution
     * @param array<int, string|null>                $hashSamples
     * @param list<Media>                            $members
     *
     * @return array<string, mixed>
     */
    private function buildSelectionTelemetry(
        ClusterMemberSelectionProfile $profile,
        SelectionResult $result,
        int $preCount,
        int $postCount,
        int $droppedCount,
        array $spacing,
        array $perDayDistribution,
        array $hashSamples,
        array $members,
        string $storyline,
    ): array {
        $rawTelemetry = $result->getTelemetry();

        $rejections = [];
        if (isset($rawTelemetry['rejections']) && is_array($rawTelemetry['rejections'])) {
            $rejections = $this->normaliseCounts($rawTelemetry['rejections']);
        }

        $drops = [
            'prefilter' => [
                'total'             => (int) ($rawTelemetry['prefilter_total'] ?? 0),
                'no_show'           => (int) ($rawTelemetry['prefilter_no_show'] ?? 0),
                'low_quality'       => (int) ($rawTelemetry['prefilter_low_quality'] ?? 0),
                'quality_floor'     => (int) ($rawTelemetry['prefilter_quality_floor'] ?? 0),
            ],
            'selection' => [
                'burst_collapsed'          => (int) ($rawTelemetry['burst_collapsed'] ?? 0),
                'day_limit_rejections'     => (int) ($rawTelemetry['day_limit_rejections'] ?? 0),
                'time_slot_rejections'     => (int) ($rawTelemetry['time_slot_rejections'] ?? 0),
                'staypoint_rejections'     => (int) ($rawTelemetry['staypoint_rejections'] ?? 0),
                'spacing_rejections'       => (int) ($rawTelemetry['spacing_rejections'] ?? 0),
                'near_duplicate_blocked'   => (int) ($rawTelemetry['near_duplicate_blocked'] ?? 0),
                'near_duplicate_replacements'=> (int) ($rawTelemetry['near_duplicate_replacements'] ?? 0),
                'fallback_used'            => (int) ($rawTelemetry['fallback_used'] ?? 0),
            ],
        ];

        $fallbacks = [
            SelectionTelemetry::REASON_TIME_GAP    => $drops['selection']['spacing_rejections'] ?? 0,
            SelectionTelemetry::REASON_DAY_QUOTA   => $drops['selection']['day_limit_rejections'] ?? 0,
            SelectionTelemetry::REASON_TIME_SLOT   => $drops['selection']['time_slot_rejections'] ?? 0,
            SelectionTelemetry::REASON_PHASH       => $drops['selection']['near_duplicate_blocked'] ?? 0,
            SelectionTelemetry::REASON_STAYPOINT   => $drops['selection']['staypoint_rejections'] ?? 0,
            SelectionTelemetry::REASON_SCENE       => 0,
            SelectionTelemetry::REASON_ORIENTATION => 0,
            SelectionTelemetry::REASON_PEOPLE      => 0,
        ];

        foreach ($fallbacks as $reason => $fallback) {
            $value = $rejections[$reason] ?? $fallback;
            $rejections[$reason] = (int) $value;
        }

        $drops['selection']['spacing_rejections']      = $rejections[SelectionTelemetry::REASON_TIME_GAP];
        $drops['selection']['near_duplicate_blocked']  = $rejections[SelectionTelemetry::REASON_PHASH];
        $drops['selection']['staypoint_rejections']    = $rejections[SelectionTelemetry::REASON_STAYPOINT];
        $drops['selection']['day_limit_rejections']    = $rejections[SelectionTelemetry::REASON_DAY_QUOTA];
        $drops['selection']['time_slot_rejections']    = $rejections[SelectionTelemetry::REASON_TIME_SLOT];

        $perBucket = $this->countPerBucket($members, $profile);
        $phash     = $this->buildPhashTelemetry($members);
        $averages  = $this->buildSelectionAverages(
            $preCount,
            $postCount,
            $perDayDistribution,
            $perBucket,
            $spacing,
            $phash,
        );
        $hints = $this->buildRelaxationHints($profile, $drops, $averages, $preCount, $postCount);

        $telemetry = $rawTelemetry;
        $telemetry['counts'] = [
            'pre'     => $preCount,
            'post'    => $postCount,
            'dropped' => $droppedCount,
        ];
        $telemetry['drops'] = $drops;
        $telemetry['spacing'] = [
            'average_seconds' => $spacing['average'],
            'samples'         => $spacing['samples'],
        ];
        $telemetry['phash'] = $phash;
        $telemetry['distribution'] = [
            'per_day'    => $perDayDistribution,
            'per_bucket' => $perBucket,
        ];
        $telemetry['hash_samples'] = $hashSamples;
        $telemetry['averages'] = $averages;
        $telemetry['relaxation_hints'] = $hints;
        $telemetry['storyline'] = $storyline;
        $telemetry['rejections'] = $rejections;

        return $telemetry;
    }

    /**
     * @param list<Media> $members
     *
     * @return array<string, int>
     */
    private function countPerBucket(array $members, ClusterMemberSelectionProfile $profile): array
    {
        $distribution = [];
        $slotSize     = max(1, $profile->getOptions()->timeSlotHours);

        foreach ($members as $media) {
            $objectId = spl_object_id($media);
            $dayKey   = $this->dayIndex[$objectId] ?? null;
            $summary  = $dayKey !== null ? ($this->daySummaries[$dayKey] ?? null) : null;

            $timezoneIdentifier = $this->resolveTimezoneIdentifier($media, $summary);
            $bucketKey          = $this->buildBucketKey($media, $timezoneIdentifier, $slotSize, $dayKey);

            $distribution[$bucketKey] = ($distribution[$bucketKey] ?? 0) + 1;
        }

        return $distribution;
    }

    /**
     * @param array<string, mixed>|null $summary
     */
    private function resolveTimezoneIdentifier(Media $media, ?array $summary): string
    {
        $identifier = null;
        if ($summary !== null && isset($summary['localTimezoneIdentifier']) && is_string($summary['localTimezoneIdentifier'])) {
            $identifier = $summary['localTimezoneIdentifier'];
        }

        if (is_string($identifier) && $identifier !== '') {
            return $identifier;
        }

        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            return $takenAt->getTimezone()->getName();
        }

        return 'UTC';
    }

    private function buildBucketKey(Media $media, string $timezoneIdentifier, int $slotSize, ?string $dayKey): string
    {
        try {
            $timezone = new DateTimeZone($timezoneIdentifier);
        } catch (Throwable) {
            $timezone = new DateTimeZone('UTC');
        }

        $time  = $media->getTakenAt() ?? $media->getCreatedAt();
        $local = $time->setTimezone($timezone);
        $hour  = (int) $local->format('H');
        $slot  = intdiv($hour, $slotSize);
        $day   = $dayKey ?? $local->format('Y-m-d');

        return $day . '#slot_' . $slot;
    }

    /**
     * @param list<Media> $members
     *
     * @return array{
     *     samples: array<int, string|null>,
     *     consecutive_hamming: list<int>,
     *     average_consecutive_hamming: float|null
     * }
     */
    private function buildPhashTelemetry(array $members): array
    {
        $samples   = [];
        $distances = [];
        $sum       = 0;
        $count     = 0;

        $previous = null;

        foreach ($members as $media) {
            $key      = $media->getId() ?? spl_object_id($media);
            $hashHex  = $this->resolvePhashHex($media);
            $samples[$key] = $hashHex;

            if ($hashHex === null || $previous === null) {
                $previous = $hashHex;

                continue;
            }

            $distance   = Phash::hammingFromHex($previous, $hashHex);
            $distances[] = $distance;
            $sum        += $distance;
            ++$count;
            $previous    = $hashHex;
        }

        $average = null;
        if ($count > 0) {
            $average = $sum / $count;
        }

        return [
            'samples'                     => $samples,
            'consecutive_hamming'         => $distances,
            'average_consecutive_hamming' => $average,
        ];
    }

    /**
     * @param array<string, int>                     $perDayDistribution
     * @param array<string, int>                     $perBucket
     * @param array{average: float, samples: list<int>} $spacing
     * @param array{
     *     samples: array<int, string|null>,
     *     consecutive_hamming: list<int>,
     *     average_consecutive_hamming: float|null
     * } $phash
     *
     * @return array<string, float|null>
     */
    private function buildSelectionAverages(
        int $preCount,
        int $postCount,
        array $perDayDistribution,
        array $perBucket,
        array $spacing,
        array $phash,
    ): array {
        $keepRatio = 0.0;
        if ($preCount > 0) {
            $keepRatio = $postCount / $preCount;
        }

        $averagePerDay = 0.0;
        if ($perDayDistribution !== []) {
            $averagePerDay = array_sum($perDayDistribution) / count($perDayDistribution);
        }

        $averagePerBucket = 0.0;
        if ($perBucket !== []) {
            $averagePerBucket = array_sum($perBucket) / count($perBucket);
        }

        return [
            'keep_ratio'                 => $keepRatio,
            'per_day'                    => $averagePerDay,
            'per_bucket'                 => $averagePerBucket,
            'spacing_seconds'            => $spacing['average'],
            'phash_hamming'              => $phash['average_consecutive_hamming'],
        ];
    }

    /**
     * @param array{
     *     prefilter: array<string, int>,
     *     selection: array<string, int>
     * } $drops
     * @param array<string, float|null> $averages
     *
     * @return list<string>
     */
    private function buildRelaxationHints(
        ClusterMemberSelectionProfile $profile,
        array $drops,
        array $averages,
        int $preCount,
        int $postCount,
    ): array {
        $hints      = [];
        $selection  = $drops['selection'];
        $prefilter  = $drops['prefilter'];
        $options    = $profile->getOptions();

        if (($selection['day_limit_rejections'] ?? 0) > 0) {
            $hints[] = 'max_per_day erhöhen, um Tagesbegrenzungen zu lockern.';
        }

        if (($selection['time_slot_rejections'] ?? 0) > 0) {
            $hints[] = 'time_slot_hours erhöhen, um mehr Medien pro Zeitfenster zu behalten.';
        }

        if (($selection['staypoint_rejections'] ?? 0) > 0) {
            $hints[] = 'max_per_staypoint anheben, um mehr Medien pro Aufenthaltsort zu behalten.';
        }

        if (($selection['spacing_rejections'] ?? 0) > 0) {
            $hints[] = 'min_spacing_seconds reduzieren, um engere Serien zu erlauben.';
        }

        if (($selection['near_duplicate_blocked'] ?? 0) > 0) {
            $hints[] = 'phash_min_hamming senken, damit ähnliche Motive nicht verworfen werden.';
        }

        if (($prefilter['quality_floor'] ?? 0) > 0) {
            $hints[] = 'quality_floor absenken, falls mehr schwächere Aufnahmen akzeptiert werden sollen.';
        }

        if (($prefilter['no_show'] ?? 0) > 0) {
            $hints[] = 'No-Show-Markierungen prüfen, um ausgeschlossene Medien freizugeben.';
        }

        if ($postCount < $options->targetTotal && ($selection['fallback_used'] ?? 0) > 0) {
            $hints[] = 'target_total oder Fallback-Strategie anpassen, weil zusätzliche Slots benötigt wurden.';
        }

        if ($preCount > 0 && $averages['keep_ratio'] !== null && $averages['keep_ratio'] < 0.5) {
            $hints[] = 'Auswahlquote ist niedrig: Zielwerte für target_total oder Filter prüfen.';
        }

        return $hints;
    }

    private function resetCaches(): void
    {
        $this->timestampCache = [];
        $this->phashCache     = [];
        $this->dayIndex       = [];
        $this->daySummaries   = [];
    }

    private function resolveTimestamp(Media $media): int
    {
        $key = spl_object_id($media);
        if (isset($this->timestampCache[$key])) {
            return $this->timestampCache[$key];
        }

        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            $timestamp = $takenAt->getTimestamp();
        } else {
            $timestamp = $media->getCreatedAt()->getTimestamp();
        }

        $this->timestampCache[$key] = $timestamp;

        return $timestamp;
    }

    private function resolvePhashHex(Media $media): ?string
    {
        $key = spl_object_id($media);
        if (array_key_exists($key, $this->phashCache)) {
            return $this->phashCache[$key];
        }

        $hash = $media->getPhash64();
        if (is_string($hash) && $hash !== '') {
            $this->phashCache[$key] = $hash;

            return $hash;
        }

        $hash = $media->getPhash();
        if (is_string($hash) && $hash !== '') {
            $this->phashCache[$key] = $hash;

            return $hash;
        }

        $this->phashCache[$key] = null;

        return null;
    }
}
