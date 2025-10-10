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
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Selection\MemberSelectorInterface;
use MagicSunday\Memories\Clusterer\Selection\SelectionResult;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterMemberSelectionServiceInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;

use function array_key_exists;
use function array_map;
use function array_sum;
use function count;
use function gmdate;
use function hex2bin;
use function is_array;
use function is_int;
use function max;
use function spl_object_id;

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

    public function __construct(
        private readonly MemberSelectorInterface $memberSelector,
        private readonly MemberMediaLookupInterface $mediaLookup,
        private readonly ClusterMemberSelectionProfileProvider $profileProvider,
    ) {
    }

    public function curate(ClusterDraft $draft): ClusterDraft
    {
        $members = $draft->getMembers();
        if ($members === []) {
            return $draft;
        }

        $this->resetCaches();

        $media = $this->loadMedia($members);
        if ($media === []) {
            return $draft;
        }

        $profile      = $this->profileProvider->resolve($draft);
        $daySummaries = $this->buildDaySummaries($media);
        if ($daySummaries === []) {
            return $draft;
        }

        $result = $this->memberSelector->select($daySummaries, $profile->getHome(), $profile->getOptions());

        return $this->applySelection($draft, $media, $result, $profile);
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
    ): ClusterDraft {
        $curatedMembers = $result->getMembers();
        $preCount       = count($media);
        $postCount      = count($curatedMembers);

        $params = $draft->getParams();
        $params['member_selection'] = $this->buildSelectionMetadata(
            $profile,
            $preCount,
            $postCount,
            $result,
            $curatedMembers,
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
    ): array {
        $droppedCount = $preCount > $postCount ? $preCount - $postCount : 0;
        $spacing      = $this->computeSpacingSamples($members);

        return [
            'profile' => $profile->getKey(),
            'counts'  => [
                'pre'     => $preCount,
                'post'    => $postCount,
                'dropped' => $droppedCount,
            ],
            'spacing' => [
                'average_seconds' => $spacing['average'],
                'samples'         => $spacing['samples'],
            ],
            'per_day_distribution' => $this->countPerDay($members),
            'options' => [
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
            ],
            'hash_samples' => $this->collectHashSamples($members),
            'telemetry'    => $result->getTelemetry(),
        ];
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
            $hashes[$id ?? spl_object_id($media)] = $this->decodePhash($media);
        }

        return $hashes;
    }

    private function resetCaches(): void
    {
        $this->timestampCache = [];
        $this->phashCache     = [];
        $this->dayIndex       = [];
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

    private function decodePhash(Media $media): ?string
    {
        $key = spl_object_id($media);
        if (array_key_exists($key, $this->phashCache)) {
            return $this->phashCache[$key];
        }

        $hash = $media->getPhash64();
        if ($hash === null) {
            $hash = $media->getPhash();
        }

        if ($hash === null) {
            $this->phashCache[$key] = null;

            return null;
        }

        $decoded = hex2bin($hash);
        if ($decoded === false) {
            $this->phashCache[$key] = $hash;

            return $hash;
        }

        $this->phashCache[$key] = $decoded;

        return $decoded;
    }
}
