<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Contract\StaypointCandidateProviderInterface;
use MagicSunday\Memories\Utility\S2CellId;
use MagicSunday\Memories\Value\PlaceId;

use function count;
use function floor;
use function max;
use function min;
use function sort;
use function spl_object_hash;

/**
 * Derives staypoint-based place identifiers from dense GPS observations.
 */
final class StaypointPlaceHeuristic implements SingleMetadataExtractorInterface
{
    private StaypointCandidateProviderInterface $candidateProvider;

    private int $minSamples;

    private int $minDurationMinutes;

    private int $maxSamples;

    private int $s2Level;

    private string $provider;

    public function __construct(
        StaypointCandidateProviderInterface $candidateProvider,
        int $minSamples = 5,
        int $minDurationMinutes = 30,
        int $maxSamples = 500,
        int $s2Level = 12,
        string $provider = 'staypoint:s2',
    ) {
        $this->candidateProvider  = $candidateProvider;
        $this->minSamples         = max(1, $minSamples);
        $this->minDurationMinutes = max(1, $minDurationMinutes);
        $this->maxSamples         = max(1, $maxSamples);
        $this->s2Level            = max(0, $s2Level);
        $this->provider           = $provider;
    }

    public function supports(string $filepath, Media $media): bool
    {
        return $media->getGpsLat() !== null
            && $media->getGpsLon() !== null
            && $media->getTakenAt() instanceof DateTimeImmutable
            && $media->getGeoCell8() !== null;
    }

    public function extract(string $filepath, Media $media): Media
    {
        if ($media->getPlaceId() instanceof PlaceId) {
            return $media;
        }

        if ($this->supports($filepath, $media) === false) {
            return $media;
        }

        $candidates = $this->candidateProvider->findCandidates($media, $this->maxSamples);
        $timestamps = $this->collectTimestamps($media, $candidates);

        if ($timestamps === []) {
            return $media;
        }

        sort($timestamps);

        $samples = count($timestamps);
        $first   = $timestamps[0];
        $last    = $timestamps[$samples - 1];

        $durationMinutes = (int) floor(($last->getTimestamp() - $first->getTimestamp()) / 60);

        if ($samples < $this->minSamples || $durationMinutes < $this->minDurationMinutes) {
            return $media;
        }

        $identifier = $media->getS2CellId();
        if ($identifier === null) {
            $identifier = S2CellId::tokenFromDegrees((float) $media->getGpsLat(), (float) $media->getGpsLon(), $this->s2Level);
            $media->setS2CellId($identifier);
        }

        $confidenceSamples  = min(1.0, $samples / ($this->minSamples * 2.0));
        $confidenceDuration = min(1.0, $durationMinutes / ($this->minDurationMinutes * 2.0));
        $confidence         = max($confidenceSamples, $confidenceDuration);

        $meta = [
            'samples'          => $samples,
            'durationMinutes'  => $durationMinutes,
            'cellLevel'        => $this->s2Level,
            'geoCell8'         => $media->getGeoCell8(),
            'source'           => 'staypoint',
        ];

        $media->setPlaceId(new PlaceId($this->provider, $identifier, $confidence, $meta));

        return $media;
    }

    /**
     * @param list<Media> $candidates
     *
     * @return list<DateTimeImmutable>
     */
    private function collectTimestamps(Media $seed, array $candidates): array
    {
        $seen = [];

        $timestamps = [];

        $seedTakenAt = $seed->getTakenAt();
        if ($seedTakenAt instanceof DateTimeImmutable) {
            $timestamps[]                 = $seedTakenAt;
            $seen[$seed->getId() ?: spl_object_hash($seed)] = true;
        }

        foreach ($candidates as $candidate) {
            $takenAt = $candidate->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $key = $candidate->getId() ?: spl_object_hash($candidate);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key]   = true;
            $timestamps[] = $takenAt;
        }

        return $timestamps;
    }
}
