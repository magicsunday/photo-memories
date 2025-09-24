<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\PlaceLabelHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 81])]
final class LocationSimilarityStrategy extends AbstractTimeGapClusterStrategy
{
    use PlaceLabelHelperTrait;

    private const GROUP_GPS = '__gps__';
    private const LOCALITY_PREFIX = 'locality:';

    private ?float $anchorLat = null;
    private ?float $anchorLon = null;
    private ?int $firstTimestamp = null;
    private readonly int $maxSpanSeconds;

    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly float $radiusMeters = 150.0,
        int $minItems = 5,
        int $maxSpanHours = 24,
        string $timezone = 'UTC',
    ) {
        $this->maxSpanSeconds = $maxSpanHours * 3600;

        parent::__construct($timezone, \PHP_INT_MAX, $minItems);
    }

    public function name(): string
    {
        return 'location_similarity';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return true;
    }

    protected function groupKey(Media $media, DateTimeImmutable $local): ?string
    {
        $locality = $this->locHelper->localityKeyForMedia($media);
        if ($locality !== null) {
            return self::LOCALITY_PREFIX . $locality;
        }

        if ($media->getGpsLat() !== null && $media->getGpsLon() !== null) {
            return self::GROUP_GPS;
        }

        return null;
    }

    /**
     * @param list<Media> $members
     * @return array<string, mixed>
     */
    protected function sessionParams(array $members): array
    {
        $groupKey = $this->currentGroupKey();

        if ($groupKey !== null && $this->isLocalityGroup($groupKey)) {
            $params = ['place_key' => $this->localityFromGroup($groupKey)];

            return $this->withMajorityPlace($members, $params);
        }

        return $this->withMajorityPlace($members);
    }

    protected function shouldSplitSession(Media $previous, Media $current, int $gapSeconds): bool
    {
        if (!$this->isGpsGroup($this->currentGroupKey())) {
            return false;
        }

        if ($gapSeconds > $this->maxSpanSeconds) {
            return true;
        }

        $currentTimestamp = $current->getTakenAt()?->getTimestamp();
        if ($this->firstTimestamp !== null && $currentTimestamp !== null) {
            if (($currentTimestamp - $this->firstTimestamp) > $this->maxSpanSeconds) {
                return true;
            }
        }

        $lat = $current->getGpsLat();
        $lon = $current->getGpsLon();

        if ($lat === null || $lon === null || $this->anchorLat === null || $this->anchorLon === null) {
            return false;
        }

        $distance = MediaMath::haversineDistanceInMeters(
            $this->anchorLat,
            $this->anchorLon,
            (float) $lat,
            (float) $lon
        );

        return $distance > $this->radiusMeters;
    }

    protected function onMediaAppended(Media $media): void
    {
        if (!$this->isGpsGroup($this->currentGroupKey())) {
            return;
        }

        if ($this->firstTimestamp === null) {
            $takenAt = $media->getTakenAt();
            if ($takenAt instanceof DateTimeImmutable) {
                $this->firstTimestamp = $takenAt->getTimestamp();
            }
        }

        if ($this->anchorLat === null || $this->anchorLon === null) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();

            if ($lat !== null && $lon !== null) {
                $this->anchorLat = (float) $lat;
                $this->anchorLon = (float) $lon;
            }
        }
    }

    protected function onSessionReset(): void
    {
        $this->anchorLat = null;
        $this->anchorLon = null;
        $this->firstTimestamp = null;
    }

    private function isGpsGroup(?string $groupKey): bool
    {
        return $groupKey === self::GROUP_GPS;
    }

    private function isLocalityGroup(string $groupKey): bool
    {
        return \str_starts_with($groupKey, self::LOCALITY_PREFIX);
    }

    private function localityFromGroup(string $groupKey): string
    {
        return \substr($groupKey, \strlen(self::LOCALITY_PREFIX));
    }
}

