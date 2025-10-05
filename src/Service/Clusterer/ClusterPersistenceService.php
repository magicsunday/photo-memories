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
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterPersistenceInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Utility\GeoCell;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_find;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function ksort;
use function sha1;
use function spl_object_id;

/**
 * Class ClusterPersistenceService.
 */
final readonly class ClusterPersistenceService implements ClusterPersistenceInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MemberMediaLookupInterface $mediaLookup,
        private CoverPickerInterface $coverPicker,
        private int $defaultBatchSize = 10,
        #[Autowire('%memories.cluster.persistence.max_members%')]
        private int $maxMembers = 20,
    ) {
    }

    /**
     * Persist drafts in batches while skipping already existing (algorithm,fingerprint) pairs.
     *
     * @param list<ClusterDraft>                   $drafts
     * @param int                                  $batchSize
     * @param callable(int $persistedInBatch)|null $onBatchPersisted
     *
     * @return int Number of newly persisted clusters
     */
    public function persistBatched(array $drafts, int $batchSize, ?callable $onBatchPersisted): int
    {
        if ($drafts === []) {
            return 0;
        }

        $batchSize = $batchSize > 0 ? $batchSize : $this->defaultBatchSize;

        // 1) Build pair list (alg, fp) for all drafts
        /** @var list<array{alg:string, fp:string}> $pairs */
        $pairs = [];
        foreach ($drafts as $d) {
            $alg     = $d->getAlgorithm();
            $ordered = $this->resolveOrderedMembers($d);
            $members = $this->clampMembers($ordered);
            $fp      = Cluster::computeFingerprint($members);
            $pairs[] = ['alg' => $alg, 'fp' => $fp];
        }

        // 2) Load existing pairs from DB into a set: "alg|fp" => true
        $existing = $this->loadExistingPairs($pairs);

        // Also prevent duplicates within this run:
        /** @var array<string, bool> $seenThisRun */
        $seenThisRun = [];

        $persisted = 0;
        $inBatch   = 0;

        // 3) Persist only new pairs
        foreach ($drafts as $d) {
            $alg     = $d->getAlgorithm();
            $ordered = $this->resolveOrderedMembers($d);
            $members = $this->clampMembers($ordered);
            $fp      = Cluster::computeFingerprint($members);
            $key     = $alg . '|' . $fp;
            if (isset($existing[$key])) {
                // already persisted earlier or within this same run
                continue;
            }

            if (isset($seenThisRun[$key])) {
                // already persisted earlier or within this same run
                continue;
            }

            $media    = $this->hydrateMembers($members);
            $metadata = $this->buildMetadata($d, $members, $media);

            // Construct and fill entity
            $entity = new Cluster(
                $alg,
                $d->getParams(),
                $d->getCentroid(),
                $members
            );

            $entity->setStartAt($metadata['startAt']);
            $entity->setEndAt($metadata['endAt']);
            $entity->setMembersCount($metadata['membersCount']);
            $entity->setPhotoCount($metadata['photoCount']);
            $entity->setVideoCount($metadata['videoCount']);
            $entity->setCover($metadata['cover']);
            $entity->setLocation($metadata['location']);
            $entity->setAlgorithmVersion($metadata['algorithmVersion']);
            $entity->setConfigHash($metadata['configHash']);
            $entity->setCentroidLat($metadata['centroidLat']);
            $entity->setCentroidLon($metadata['centroidLon']);
            $entity->setCentroidCell7($metadata['centroidCell7']);

            $this->em->persist($entity);

            ++$persisted;
            ++$inBatch;
            $seenThisRun[$key] = true;

            if ($inBatch >= $batchSize) {
                $this->em->flush();
                $this->em->clear();

                if ($onBatchPersisted !== null) {
                    $onBatchPersisted($inBatch);
                }

                $inBatch = 0;
            }
        }

        if ($inBatch > 0) {
            $this->em->flush();
            $this->em->clear();

            if ($onBatchPersisted !== null) {
                $onBatchPersisted($inBatch);
            }
        }

        return $persisted;
    }

    /**
     * Load already persisted (algorithm,fingerprint) pairs for the given candidate set.
     *
     * @param list<array{alg:string, fp:string}> $pairs
     *
     * @return array<string,bool> map "alg|fp" => true
     */
    private function loadExistingPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        // Deduplicate parameters to keep the IN-clauses small
        $algs = [];
        $fps  = [];
        foreach ($pairs as $p) {
            $algs[$p['alg']] = true;
            $fps[$p['fp']]   = true;
        }

        /** @var list<string> $algList */
        $algList = array_keys($algs);
        /** @var list<string> $fpList */
        $fpList = array_keys($fps);

        $qb = $this->em->createQueryBuilder()
            ->select('c.algorithm AS alg', 'c.fingerprint AS fp')
            ->from(Cluster::class, 'c')
            ->where('c.algorithm IN (:algs)')
            ->andWhere('c.fingerprint IN (:fps)')
            ->setParameter('algs', $algList)
            ->setParameter('fps', $fpList);

        $q = $qb->getQuery();

        /** @var list<array{alg:string, fp:string}> $rows */
        $rows = $q->getResult();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['alg'] . '|' . $r['fp']] = true;
        }

        return $out;
    }

    /**
     * Remove all persisted clusters for the provided algorithm list.
     *
     * @param list<string> $algorithms
     */
    public function deleteByAlgorithms(array $algorithms): int
    {
        if ($algorithms === []) {
            return 0;
        }

        $uniqueAlgorithms = array_values(array_unique($algorithms));

        $q = $this->em->createQueryBuilder()
            ->delete(Cluster::class, 'c')
            ->where('c.algorithm IN (:algs)')
            ->setParameter('algs', $uniqueAlgorithms)
            ->getQuery();

        $deleted = (int) $q->execute();

        $this->em->clear();

        return $deleted;
    }

    /**
     * @param ClusterDraft $draft
     * @param list<int>    $memberIds
     * @param list<Media>  $media
     *
     * @return array{
     *     startAt: ?DateTimeImmutable,
     *     endAt: ?DateTimeImmutable,
     *     membersCount: int,
     *     photoCount: ?int,
     *     videoCount: ?int,
     *     cover: ?Media,
     *     location: ?Location,
     *     algorithmVersion: ?string,
     *     configHash: ?string,
     *     centroidLat: ?float,
     *     centroidLon: ?float,
     *     centroidCell7: ?string
     * }
     *
     * @throws JsonException
     */
    private function buildMetadata(ClusterDraft $draft, array $memberIds, array $media): array
    {
        $bounds       = $this->resolveTemporalBounds($media);
        $membersCount = count($memberIds);

        $photoCount = null;
        $videoCount = null;
        if ($media !== []) {
            $counts     = $this->countMembersByKind($media);
            $photoCount = $counts['photos'];
            $videoCount = $counts['videos'];
        }

        $cover            = $media !== [] ? $this->coverPicker->pickCover($media, $draft->getParams()) : null;
        $location         = $this->resolveDominantLocation($media);
        $algorithmVersion = $this->resolveAlgorithmVersion($draft->getParams());
        $configHash       = $this->computeConfigHash($draft->getParams());

        $centroid     = $draft->getCentroid();
        $centroidLat  = $this->numericOrNull($centroid['lat'] ?? null);
        $centroidLon  = $this->numericOrNull($centroid['lon'] ?? null);
        $centroidCell = null;
        if ($centroidLat !== null && $centroidLon !== null) {
            $centroidCell = GeoCell::fromPoint($centroidLat, $centroidLon, 7);
        }

        $draft->setStartAt($bounds['start']);
        $draft->setEndAt($bounds['end']);
        $draft->setMembersCount($membersCount);
        $draft->setPhotoCount($photoCount);
        $draft->setVideoCount($videoCount);
        $draft->setCoverMediaId($cover?->getId());
        $draft->setLocation($location);
        $draft->setAlgorithmVersion($algorithmVersion);
        $draft->setConfigHash($configHash);
        $draft->setCentroidLat($centroidLat);
        $draft->setCentroidLon($centroidLon);
        $draft->setCentroidCell7($centroidCell);

        return [
            'startAt'          => $bounds['start'],
            'endAt'            => $bounds['end'],
            'membersCount'     => $membersCount,
            'photoCount'       => $photoCount,
            'videoCount'       => $videoCount,
            'cover'            => $cover,
            'location'         => $location,
            'algorithmVersion' => $algorithmVersion,
            'configHash'       => $configHash,
            'centroidLat'      => $centroidLat,
            'centroidLon'      => $centroidLon,
            'centroidCell7'    => $centroidCell,
        ];
    }

    /**
     * @param list<int> $memberIds
     *
     * @return list<Media>
     */
    private function hydrateMembers(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $loaded = $this->mediaLookup->findByIds($memberIds);
        if ($loaded === []) {
            return [];
        }

        /** @var array<int, Media> $map */
        $map = [];
        foreach ($loaded as $media) {
            $map[$media->getId()] = $media;
        }

        $ordered = [];
        foreach ($memberIds as $id) {
            $media = $map[$id] ?? null;
            if ($media instanceof Media) {
                $ordered[] = $media;
            }
        }

        return $ordered;
    }

    /**
     * @param list<Media> $media
     *
     * @return array{start:?DateTimeImmutable,end:?DateTimeImmutable}
     */
    private function resolveTemporalBounds(array $media): array
    {
        $start = null;
        $end   = null;

        foreach ($media as $item) {
            $taken = $item->getTakenAt();
            if (!$taken instanceof DateTimeImmutable) {
                continue;
            }

            if ($start === null || $taken < $start) {
                $start = $taken;
            }

            if ($end === null || $taken > $end) {
                $end = $taken;
            }
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param list<Media> $media
     *
     * @return array{photos:int,videos:int}
     */
    private function countMembersByKind(array $media): array
    {
        $photos = 0;
        $videos = 0;

        foreach ($media as $item) {
            if ($item->isVideo()) {
                ++$videos;
                continue;
            }

            ++$photos;
        }

        return ['photos' => $photos, 'videos' => $videos];
    }

    /**
     * @param list<Media> $media
     */
    private function resolveDominantLocation(array $media): ?Location
    {
        $counts = [];

        foreach ($media as $item) {
            $location = $item->getLocation();
            if (!$location instanceof Location) {
                continue;
            }

            $id  = $location->getId();
            $key = $id !== null ? 'id_' . $id : 'obj_' . spl_object_id($location);

            if (!isset($counts[$key])) {
                $counts[$key] = ['location' => $location, 'count' => 0];
            }

            ++$counts[$key]['count'];
        }

        $dominant = null;
        foreach ($counts as $entry) {
            if ($dominant === null || $entry['count'] > $dominant['count']) {
                $dominant = $entry;
            }
        }

        return $dominant['location'] ?? null;
    }

    /**
     * @param array<string, scalar|array|null> $params
     */
    private function resolveAlgorithmVersion(array $params): ?string
    {
        $candidates = [
            $params['algorithm_version'] ?? null,
            $params['algorithmVersion'] ?? null,
            $params['version'] ?? null,
        ];

        $candidate = array_find(
            $candidates,
            static fn ($value): bool => (is_string($value) && $value !== '') || is_int($value)
        );

        if (is_string($candidate)) {
            return $candidate;
        }

        if (is_int($candidate)) {
            return (string) $candidate;
        }

        return null;
    }

    /**
     * @param array<string, scalar|array|null> $params
     *
     * @return string|null
     *
     * @throws JsonException
     */
    private function computeConfigHash(array $params): ?string
    {
        if ($params === []) {
            return null;
        }

        $normalized = $this->normaliseParamsForHash($params);
        $encoded    = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR
        );
        if ($encoded === false) {
            return null;
        }

        return sha1($encoded);
    }

    /**
     * @param array<string|int, mixed> $value
     *
     * @return array<string|int, mixed>
     */
    private function normaliseParamsForHash(array $value): array
    {
        if (array_is_list($value)) {
            foreach ($value as $index => $entry) {
                if (is_array($entry)) {
                    $value[$index] = $this->normaliseParamsForHash($entry);
                }
            }

            return $value;
        }

        ksort($value);

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->normaliseParamsForHash($entry);
            }
        }

        return $value;
    }

    /**
     * @param array|bool|float|int|string|null $value
     */
    private function numericOrNull(array|bool|float|int|string|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function resolveOrderedMembers(ClusterDraft $draft): array
    {
        $original = $draft->getMembers();
        $params   = $draft->getParams();
        $metadata = $params['member_quality'] ?? null;
        if (!is_array($metadata)) {
            return $original;
        }

        $balanced = $this->normaliseOrderList($metadata['ordered'] ?? null, $original);

        if ($draft->getAlgorithm() === 'vacation') {
            if ($balanced !== null) {
                return $balanced;
            }

            $quality = $this->resolveQualityRankedOrder($metadata, $original);

            return $quality ?? $original;
        }

        $quality = $this->resolveQualityRankedOrder($metadata, $original);
        if ($quality !== null) {
            return $quality;
        }

        return $balanced ?? $original;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<int>            $original
     *
     * @return list<int>|null
     */
    private function resolveQualityRankedOrder(array $metadata, array $original): ?array
    {
        $qualityRanked = $metadata['quality_ranked'] ?? null;
        $quality       = $this->extractOrderedList($qualityRanked, $original);
        if ($quality !== null) {
            return $quality;
        }

        $legacyRanked = $metadata['ranked'] ?? null;

        return $this->extractOrderedList($legacyRanked, $original);
    }

    /**
     * @param array|bool|float|int|string|null $value
     * @param list<int>                        $original
     *
     * @return list<int>|null
     */
    private function extractOrderedList(array|bool|float|int|string|null $value, array $original): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        if (isset($value['ordered'])) {
            return $this->normaliseOrderList($value['ordered'], $original);
        }

        $ids = [];
        foreach ($value as $entry) {
            if (is_int($entry)) {
                $ids[] = $entry;
                continue;
            }

            if (is_numeric($entry)) {
                $ids[] = (int) $entry;
                continue;
            }

            if (is_array($entry) && array_key_exists('id', $entry)) {
                $idValue = $entry['id'];
                if (is_int($idValue)) {
                    $ids[] = $idValue;
                    continue;
                }

                if (is_numeric($idValue)) {
                    $ids[] = (int) $idValue;
                }
            }
        }

        return $this->normaliseOrderList($ids, $original);
    }

    /**
     * @param array|bool|float|int|string|null $raw
     * @param list<int>                        $original
     *
     * @return list<int>|null
     */
    private function normaliseOrderList(array|bool|float|int|string|null $raw, array $original): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        /** @var array<int,int> $originalCounts */
        $originalCounts = [];
        foreach ($original as $id) {
            $intId                  = $id;
            $originalCounts[$intId] = ($originalCounts[$intId] ?? 0) + 1;
        }

        if ($originalCounts === []) {
            return null;
        }

        /** @var list<int> $ordered */
        $ordered = [];
        /** @var array<int,int> $orderedCounts */
        $orderedCounts = [];

        foreach ($raw as $value) {
            $intValue = null;
            if (is_int($value)) {
                $intValue = $value;
            } elseif (is_numeric($value)) {
                $intValue = (int) $value;
            }

            if ($intValue === null) {
                continue;
            }

            if (!isset($originalCounts[$intValue])) {
                continue;
            }

            $ordered[]                = $intValue;
            $orderedCounts[$intValue] = ($orderedCounts[$intValue] ?? 0) + 1;
        }

        if ($ordered === []) {
            return null;
        }

        foreach ($original as $id) {
            $intId    = $id;
            $expected = $originalCounts[$intId] ?? 0;
            $current  = $orderedCounts[$intId] ?? 0;
            if ($current >= $expected) {
                continue;
            }

            $ordered[]             = $intId;
            $orderedCounts[$intId] = $current + 1;
        }

        return $ordered;
    }

    /**
     * @param list<int> $members
     *
     * @return list<int>
     */
    private function clampMembers(array $members): array
    {
        if ($this->maxMembers <= 0) {
            return $members;
        }

        if (count($members) <= $this->maxMembers) {
            return $members;
        }

        return array_slice($members, 0, $this->maxMembers);
    }
}
