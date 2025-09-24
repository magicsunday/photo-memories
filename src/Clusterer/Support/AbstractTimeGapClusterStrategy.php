<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Shared base for session-based clustering strategies that rely on time gaps between media items.
 */
abstract class AbstractTimeGapClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    private readonly DateTimeZone $timezone;
    private ?string $currentGroupKey = null;

    public function __construct(
        string $timezone,
        private readonly int $sessionGapSeconds,
        private readonly int $minItems
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    final public function cluster(array $items): array
    {
        $this->beforeGrouping();

        /** @var array<string, list<Media>> $groups */
        $groups = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $takenAt->setTimezone($this->timezone);
            if (!$this->shouldConsider($media, $local)) {
                continue;
            }

            $groupKey = $this->groupKey($media, $local);
            if ($groupKey === null) {
                continue;
            }

            $groups[$groupKey] ??= [];
            $groups[$groupKey][] = $media;
        }

        if ($groups === []) {
            return [];
        }

        $drafts = [];

        foreach ($groups as $key => $candidates) {
            if (\count($candidates) < $this->minItems) {
                continue;
            }

            \usort(
                $candidates,
                static fn (Media $a, Media $b): int => $a->getTakenAt()->getTimestamp() <=> $b->getTakenAt()->getTimestamp()
            );

            $this->currentGroupKey = $key;
            $this->onSessionReset();

            $buffer = [];
            $lastTimestamp = null;
            $lastMedia = null;

            foreach ($candidates as $media) {
                $ts = $media->getTakenAt()?->getTimestamp();
                if ($ts === null) {
                    continue;
                }

                if ($lastMedia !== null && $lastTimestamp !== null) {
                    $gap = $ts - $lastTimestamp;
                    if ($gap > $this->sessionGapSeconds || $this->shouldSplitSession($lastMedia, $media, $gap)) {
                        $this->flushBuffer($buffer, $drafts);
                        $lastMedia = null;
                        $lastTimestamp = null;
                    }
                }

                $buffer[] = $media;
                $this->onMediaAppended($media);
                $lastTimestamp = $ts;
                $lastMedia = $media;
            }

            $this->flushBuffer($buffer, $drafts);
            $this->onSessionReset();
        }

        $this->currentGroupKey = null;

        return $drafts;
    }

    /**
     * @param list<Media> $buffer
     * @param list<ClusterDraft> $drafts
     */
    private function flushBuffer(array &$buffer, array &$drafts): void
    {
        if ($buffer === []) {
            return;
        }

        if (\count($buffer) < $this->minItems) {
            $buffer = [];
            $this->onSessionReset();
            return;
        }

        $draft = $this->buildSessionDraft($buffer);
        if ($draft !== null) {
            $drafts[] = $draft;
        }

        $buffer = [];
        $this->onSessionReset();
    }

    /**
     * @param list<Media> $members
     */
    private function buildSessionDraft(array $members): ?ClusterDraft
    {
        if (!$this->isSessionValid($members)) {
            return null;
        }

        $params = $this->sessionParams($members);
        $params['time_range'] ??= $this->computeTimeRange($members);

        return $this->buildClusterDraft($this->name(), $members, $params);
    }

    /**
     * @param list<Media> $members
     */
    protected function isSessionValid(array $members): bool
    {
        return \count($members) >= $this->minItems;
    }

    /**
     * @param list<Media> $members
     * @return array<string, mixed>
     */
    protected function sessionParams(array $members): array
    {
        return [];
    }

    protected function beforeGrouping(): void
    {
        // Default no-op.
    }

    protected function groupKey(Media $media, DateTimeImmutable $local): ?string
    {
        return '__default__';
    }

    protected function currentGroupKey(): ?string
    {
        return $this->currentGroupKey;
    }

    abstract protected function shouldConsider(Media $media, DateTimeImmutable $local): bool;

    protected function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    protected function sessionGapSeconds(): int
    {
        return $this->sessionGapSeconds;
    }

    protected function minItems(): int
    {
        return $this->minItems;
    }

    protected function shouldSplitSession(Media $previous, Media $current, int $gapSeconds): bool
    {
        return false;
    }

    protected function onMediaAppended(Media $media): void
    {
        // default no-op
    }

    protected function onSessionReset(): void
    {
        // default no-op
    }

    /**
     * @param list<Media> $members
     */
    protected function allWithinRadius(array $members, float $radiusMeters): bool
    {
        $withGps = \array_values(\array_filter(
            $members,
            static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null
        ));

        if ($withGps === []) {
            return true;
        }

        $centroid = MediaMath::centroid($withGps);

        foreach ($withGps as $media) {
            $distance = MediaMath::haversineDistanceInMeters(
                (float) $centroid['lat'],
                (float) $centroid['lon'],
                (float) $media->getGpsLat(),
                (float) $media->getGpsLon()
            );

            if ($distance > $radiusMeters) {
                return false;
            }
        }

        return true;
    }

}
