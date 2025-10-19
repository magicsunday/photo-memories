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
use MagicSunday\Memories\Clusterer\Support\ClusterPeopleAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Entity\ClusterMember;
use MagicSunday\Memories\Entity\Enum\ClusterMemberRole;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterMemberSelectionServiceInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterPersistenceInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Service\Clusterer\TravelWaypointAnnotator;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use MagicSunday\Memories\Utility\GeoCell;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_chunk;
use function array_map;
use function array_find;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function ksort;
use function max;
use function min;
use function sha1;
use function spl_object_id;
use function trim;

/**
 * Class ClusterPersistenceService.
 */
final readonly class ClusterPersistenceService implements ClusterPersistenceInterface
{
    private ClusterQualityAggregator $qualityAggregator;
    private ClusterPeopleAggregator $peopleAggregator;
    private TravelWaypointAnnotator $travelWaypointAnnotator;

    public function __construct(
        private EntityManagerInterface $em,
        private MemberMediaLookupInterface $mediaLookup,
        private ClusterMemberSelectionServiceInterface $memberSelection,
        private CoverPickerInterface $coverPicker,
        private int $defaultBatchSize = 10,
        #[Autowire('%memories.cluster.persistence.max_members%')]
        private int $maxMembers = 500,
        #[Autowire('%memories.cluster.persistence.fingerprint_lookup_batch_size%')]
        private int $fingerprintLookupBatchSize = 500,
        ?ClusterQualityAggregator $qualityAggregator = null,
        ?ClusterPeopleAggregator $peopleAggregator = null,
        ?TravelWaypointAnnotator $travelWaypointAnnotator = null,
        private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
        $this->peopleAggregator  = $peopleAggregator ?? new ClusterPeopleAggregator();
        $this->travelWaypointAnnotator = $travelWaypointAnnotator ?? new TravelWaypointAnnotator();
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

        $drafts = $this->curateDrafts($drafts);

        foreach ($drafts as $draft) {
            $this->persistSelectionTelemetryOnDraft($draft);
        }

        $pairs = $this->computePairs($drafts);

        $existing    = $this->loadExistingPairs($pairs);
        $seenThisRun = [];

        $persisted = 0;
        $inBatch   = 0;

        foreach ($drafts as $draft) {
            $entity = $this->buildEntityForDraft($draft, $existing, $seenThisRun);
            if (!$entity instanceof Cluster) {
                continue;
            }

            $this->em->persist($entity);

            ++$persisted;
            ++$inBatch;

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

    public function persistStreaming(iterable $drafts, ?callable $onPersisted): int
    {
        $existing    = [];
        $seenThisRun = [];
        $persisted   = 0;

        foreach ($drafts as $draft) {
            $curated = $this->memberSelection->curate($draft);
            $this->persistSelectionTelemetryOnDraft($curated);

            $context = $this->resolveDraftContext($curated);

            $persisted += $this->flushStreamingBatch([
                [
                    'draft'       => $curated,
                    'members'     => $context['members'],
                    'fingerprint' => $context['fingerprint'],
                    'key'         => $context['key'],
                ],
            ], $existing, $seenThisRun, $onPersisted);
        }

        return $persisted;
    }

    /**
     * Refreshes a persisted cluster by re-applying the curated overlay while keeping raw members intact.
     *
     * @return array{raw_count:int, curated_count:int, overlay_count:int}
     */
    public function refreshExistingCluster(Cluster $cluster): array
    {
        [$draft, $metadata] = $this->rebuildDraftFromEntity($cluster);

        $cluster->setType($metadata['type']);
        $cluster->setMeta($metadata['meta']);
        $cluster->setParams($metadata['params']);
        $cluster->setStartAt($metadata['startAt']);
        $cluster->setEndAt($metadata['endAt']);
        $cluster->setMembersCount($metadata['membersCount']);
        $cluster->setPhotoCount($metadata['photoCount']);
        $cluster->setVideoCount($metadata['videoCount']);
        $cluster->setCover($metadata['keyMedia']);
        $cluster->setLocation($metadata['location']);
        $cluster->setAlgorithmVersion($metadata['algorithmVersion']);
        $cluster->setConfigHash($metadata['configHash']);
        $cluster->setCentroidLat($metadata['centroidLat']);
        $cluster->setCentroidLon($metadata['centroidLon']);
        $cluster->setCentroidCell7($metadata['centroidCell7']);
        $cluster->setBoundingBox($metadata['boundingBox']);
        $cluster->setScore($metadata['score']);
        $cluster->setScorePreNorm($metadata['scorePreNorm']);
        $cluster->setScorePostNorm($metadata['scorePostNorm']);
        $cluster->setScoreBoosted($metadata['scoreBoosted']);
        $cluster->setQualityScore($metadata['qualityScore']);
        $cluster->setPeopleScore($metadata['peopleScore']);

        $memberIds = $metadata['meta']['member_ids'] ?? $cluster->getMembers();
        if (!is_array($memberIds)) {
            $memberIds = $cluster->getMembers();
        }

        /** @var list<int> $memberIds */
        $memberIds = array_values(array_map(static fn (mixed $value): int => (int) $value, $memberIds));
        $media     = $this->hydrateMembers($memberIds);

        $this->attachClusterMembers($cluster, $memberIds, $media, $metadata['overlay'], $metadata['memberScores']);

        return $this->extractSelectionSummary($draft);
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<array{alg:string, fp:string}>
     */
    private function computePairs(array $drafts): array
    {
        $pairs = [];
        foreach ($drafts as $draft) {
            $context = $this->resolveDraftContext($draft);
            $pairs[] = [
                'alg' => $draft->getAlgorithm(),
                'fp'  => $context['fingerprint'],
            ];
        }

        return $pairs;
    }

    /**
     * @param array<string, bool> $existing
     * @param array<string, bool> $seenThisRun
     */
    private function buildEntityForDraft(
        ClusterDraft $draft,
        array $existing,
        array &$seenThisRun,
        ?array $members = null,
        ?string $fingerprint = null,
        ?string $key = null,
    ): ?Cluster {
        if ($members === null || $fingerprint === null || $key === null) {
            $context    = $this->resolveDraftContext($draft);
            $members    = $context['members'];
            $fingerprint = $context['fingerprint'];
            $key         = $context['key'];
        }

        if (isset($existing[$key]) || isset($seenThisRun[$key])) {
            return null;
        }

        $media    = $this->hydrateMembers($members);
        $metadata = $this->buildMetadata($draft, $members, $media);

        $entity = $this->createClusterEntity($draft, $members, $metadata, $media);

        $seenThisRun[$key] = true;

        return $entity;
    }

    /**
     * @return array{members:list<int>, fingerprint:string, key:string}
     */
    private function resolveDraftContext(ClusterDraft $draft): array
    {
        $rawMembers  = $draft->getMembers();
        $members     = $this->clampMembers($rawMembers);
        $fingerprint = Cluster::computeFingerprint($members);

        return [
            'members'     => $members,
            'fingerprint' => $fingerprint,
            'key'         => $draft->getAlgorithm() . '|' . $fingerprint,
        ];
    }

    /**
     * @param list<array{draft:ClusterDraft, members:list<int>, fingerprint:string, key:string}> $batch
     * @param array<string,bool>                                                                $existing
     * @param array<string,bool>                                                                $seenThisRun
     */
    private function flushStreamingBatch(
        array $batch,
        array &$existing,
        array &$seenThisRun,
        ?callable $onPersisted,
    ): int {
        $pairs = [];
        foreach ($batch as $entry) {
            $key = $entry['key'];
            if (isset($existing[$key]) || isset($seenThisRun[$key])) {
                continue;
            }

            $pairs[$key] = [
                'alg' => $entry['draft']->getAlgorithm(),
                'fp'  => $entry['fingerprint'],
            ];
        }

        if ($pairs !== []) {
            $resolved = $this->loadExistingPairs(array_values($pairs));
            foreach ($resolved as $resolvedKey => $flag) {
                $existing[$resolvedKey] = $flag;
            }
        }

        $persisted = 0;

        foreach ($batch as $entry) {
            $entity = $this->buildEntityForDraft(
                $entry['draft'],
                $existing,
                $seenThisRun,
                $entry['members'],
                $entry['fingerprint'],
                $entry['key'],
            );

            if (!$entity instanceof Cluster) {
                continue;
            }

            $this->em->persist($entity);
            $this->em->flush();
            $this->em->clear();

            ++$persisted;

            if ($onPersisted !== null) {
                $onPersisted(1);
            }
        }

        return $persisted;
    }

    /**
     * @param list<int> $members
     * @param array{
     *     startAt:?DateTimeImmutable,
     *     endAt:?DateTimeImmutable,
     *     membersCount:int,
     *     photoCount:?int,
     *     videoCount:?int,
     *     cover:?Media,
     *     location:?Location,
     *     algorithmVersion:?string,
     *     configHash:?string,
     *     centroidLat:?float,
     *     centroidLon:?float,
     *     centroidCell7:?string
     * } $metadata
     */
    /**
     * @param list<int> $members
     * @param array{
     *     startAt:?DateTimeImmutable,
     *     endAt:?DateTimeImmutable,
     *     membersCount:int,
     *     photoCount:?int,
     *     videoCount:?int,
     *     cover:?Media,
     *     keyMedia:?Media,
     *     location:?Location,
     *     algorithmVersion:?string,
     *     configHash:?string,
     *     centroidLat:?float,
     *     centroidLon:?float,
     *     centroidCell7:?string,
     *     params: array<string, mixed>,
     *     meta: array<string, mixed>,
     *     boundingBox: array<string, mixed>|null,
     *     score:?float,
     *     scorePreNorm:?float,
     *     scorePostNorm:?float,
     *     scoreBoosted:?float,
     *     qualityScore:?float,
     *     peopleScore:?float,
     *     type:string,
     *     overlay: list<int>,
     *     memberScores: array<int, float>,
     * } $metadata
     * @param list<Media> $media
     */
    private function createClusterEntity(ClusterDraft $draft, array $members, array $metadata, array $media): Cluster
    {
        $entity = new Cluster(
            $metadata['type'],
            $draft->getAlgorithm(),
            $metadata['params'],
            $draft->getCentroid(),
            $members,
        );

        $entity->setStartAt($metadata['startAt']);
        $entity->setEndAt($metadata['endAt']);
        $entity->setMembersCount($metadata['membersCount']);
        $entity->setPhotoCount($metadata['photoCount']);
        $entity->setVideoCount($metadata['videoCount']);
        $entity->setCover($metadata['keyMedia']);
        $entity->setLocation($metadata['location']);
        $entity->setAlgorithmVersion($metadata['algorithmVersion']);
        $entity->setConfigHash($metadata['configHash']);
        $entity->setCentroidLat($metadata['centroidLat']);
        $entity->setCentroidLon($metadata['centroidLon']);
        $entity->setCentroidCell7($metadata['centroidCell7']);
        $entity->setBoundingBox($metadata['boundingBox']);
        $entity->setScore($metadata['score']);
        $entity->setScorePreNorm($metadata['scorePreNorm']);
        $entity->setScorePostNorm($metadata['scorePostNorm']);
        $entity->setScoreBoosted($metadata['scoreBoosted']);
        $entity->setQualityScore($metadata['qualityScore']);
        $entity->setPeopleScore($metadata['peopleScore']);
        $entity->setMeta($metadata['meta']);
        $entity->setParams($metadata['params']);
        $entity->setType($metadata['type']);

        $this->attachClusterMembers(
            $entity,
            $members,
            $media,
            $metadata['overlay'],
            $metadata['memberScores'],
        );

        return $entity;
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

        $chunkSize = $this->fingerprintLookupBatchSize > 0 ? $this->fingerprintLookupBatchSize : 1;

        $existing = [];
        foreach (array_chunk($fpList, $chunkSize) as $chunk) {
            $rows     = $this->fetchExistingPairsChunk($algList, $chunk);
            $existing = $this->mergeExistingPairRows($existing, $rows);
        }

        return $existing;
    }

    /**
     * @param list<string> $algorithms
     * @param list<string> $fingerprints
     *
     * @return list<array{alg:string, fp:string}>
     */
    private function fetchExistingPairsChunk(array $algorithms, array $fingerprints): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.strategy AS alg', 'c.fingerprint AS fp')
            ->from(Cluster::class, 'c')
            ->where('c.strategy IN (:algs)')
            ->andWhere('c.fingerprint IN (:fps)')
            ->setParameter('algs', $algorithms)
            ->setParameter('fps', $fingerprints);

        $q = $qb->getQuery();

        /** @var list<array{alg:string, fp:string}> $rows */
        $rows = $q->getResult();

        return $rows;
    }

    /**
     * @param array<string, bool>               $existing
     * @param list<array{alg:string, fp:string}> $rows
     *
     * @return array<string, bool>
     */
    private function mergeExistingPairRows(array $existing, array $rows): array
    {
        foreach ($rows as $r) {
            $existing[$r['alg'] . '|' . $r['fp']] = true;
        }

        return $existing;
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
            ->where('c.strategy IN (:algs)')
            ->setParameter('algs', $uniqueAlgorithms)
            ->getQuery();

        $deleted = (int) $q->execute();

        $this->em->clear();

        return $deleted;
    }

    public function deleteAll(): int
    {
        $q = $this->em->createQueryBuilder()
            ->delete(Cluster::class, 'c')
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

        $params = $draft->getParams();

        if ($media !== []) {
            $qualityParams = $this->qualityAggregator->buildParams($media);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $draft->setParam($qualityKey, $qualityValue);
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $peopleParams = $this->peopleAggregator->buildParams($media);
            foreach ($peopleParams as $peopleKey => $peopleValue) {
                $draft->setParam($peopleKey, $peopleValue);
                $params[$peopleKey] = $peopleValue;
            }

            $annotations = $this->travelWaypointAnnotator->annotate($media);

            $waypoints = $annotations['waypoints'];
            if ($waypoints !== []) {
                $draft->setParam('travel_waypoints', $waypoints);
                $params['travel_waypoints'] = $waypoints;
            }

            $events = $annotations['events'];
            if ($events !== []) {
                $draft->setParam('travel_events', $events);
                $params['travel_events'] = $events;
            }
        }

        $cover            = $media !== [] ? $this->coverPicker->pickCover($media, $params) : null;
        $location         = $this->resolveDominantLocation($media);
        $algorithmVersion = $this->resolveAlgorithmVersion($params);
        $configHash       = $this->computeConfigHash($params);

        $centroid     = $draft->getCentroid();
        $centroidLat  = $this->numericOrNull($centroid['lat'] ?? null);
        $centroidLon  = $this->numericOrNull($centroid['lon'] ?? null);
        $centroidCell = null;
        if ($centroidLat !== null && $centroidLon !== null) {
            $centroidCell = GeoCell::fromPoint($centroidLat, $centroidLon, 7);
        }

        $boundingBox = $this->buildBoundingBox($media);
        $overlay     = $this->normaliseMemberIdList($params['member_quality']['ordered'] ?? null);
        $memberScores = $this->extractMemberScores($params);
        $type          = $this->resolveClusterType($draft, $params);

        $meta = [
            'params'     => $params,
            'member_ids' => $memberIds,
            'centroid'   => $centroid,
        ];

        $mergeTelemetry = null;
        $metaParams     = $params['meta'] ?? null;
        if (is_array($metaParams)) {
            $merges = $metaParams['merges'] ?? null;
            if (is_array($merges) && $merges !== []) {
                $mergeTelemetry = $merges;
            }
        }

        if ($mergeTelemetry !== null) {
            $meta['merges'] = $mergeTelemetry;
        }

        if ($overlay !== []) {
            $meta['overlay'] = $overlay;
        }

        if ($boundingBox !== null) {
            $meta['bounding_box'] = $boundingBox;
        }

        if ($memberScores !== []) {
            $meta['member_scores'] = $memberScores;
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
            'keyMedia'         => $cover,
            'location'         => $location,
            'algorithmVersion' => $algorithmVersion,
            'configHash'       => $configHash,
            'centroidLat'      => $centroidLat,
            'centroidLon'      => $centroidLon,
            'centroidCell7'    => $centroidCell,
            'boundingBox'      => $boundingBox,
            'score'            => $this->numericOrNull($params['score'] ?? null),
            'scorePreNorm'     => $this->numericOrNull($params['pre_norm_score'] ?? null),
            'scorePostNorm'    => $this->numericOrNull($params['post_norm_score'] ?? null),
            'scoreBoosted'     => $this->numericOrNull($params['boosted_score'] ?? null),
            'qualityScore'     => $this->numericOrNull($params['quality_avg'] ?? null),
            'peopleScore'      => $this->numericOrNull($params['people'] ?? null),
            'params'           => $params,
            'meta'             => $meta,
            'type'             => $type,
            'overlay'          => $overlay,
            'memberScores'     => $memberScores,
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
        $value = $params['algorithmVersion'] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
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
     * @param list<Media> $media
     *
     * @return array<string, mixed>|null
     */
    private function buildBoundingBox(array $media): ?array
    {
        $minLat = null;
        $maxLat = null;
        $minLon = null;
        $maxLon = null;

        foreach ($media as $item) {
            $lat = $item->getGpsLat();
            $lon = $item->getGpsLon();

            if ($lat === null || $lon === null) {
                continue;
            }

            $minLat = $minLat === null ? $lat : min($minLat, $lat);
            $maxLat = $maxLat === null ? $lat : max($maxLat, $lat);
            $minLon = $minLon === null ? $lon : min($minLon, $lon);
            $maxLon = $maxLon === null ? $lon : max($maxLon, $lon);
        }

        if ($minLat === null || $maxLat === null || $minLon === null || $maxLon === null) {
            return null;
        }

        return [
            'type'        => 'Polygon',
            'coordinates' => [[
                [$minLon, $minLat],
                [$minLon, $maxLat],
                [$maxLon, $maxLat],
                [$maxLon, $minLat],
                [$minLon, $minLat],
            ]],
        ];
    }

    /**
     * @param list<Media> $media
     *
     * @return array<int, Media>
     */
    private function indexMediaById(array $media): array
    {
        $map = [];

        foreach ($media as $item) {
            $id = $item->getId();
            if ($id === null) {
                continue;
            }

            $map[$id] = $item;
        }

        return $map;
    }

    /**
     * @param list<int>   $memberIds
     * @param list<Media> $media
     * @param list<int>   $overlay
     * @param array<int,float> $memberScores
     */
    private function attachClusterMembers(Cluster $cluster, array $memberIds, array $media, array $overlay, array $memberScores): void
    {
        $cluster->clearClusterMembers();

        $mediaMap   = $this->indexMediaById($media);
        $overlayMap = [];
        foreach ($overlay as $rank => $memberId) {
            $overlayMap[$memberId] = $rank;
        }

        $primaryId = $overlay[0] ?? ($memberIds[0] ?? null);

        foreach ($memberIds as $index => $memberId) {
            $mediaItem = $mediaMap[$memberId] ?? null;
            if (!$mediaItem instanceof Media) {
                continue;
            }

            $role = ClusterMemberRole::DUPLICATE;
            if ($memberId === $primaryId) {
                $role = ClusterMemberRole::PRIMARY;
            } elseif (isset($overlayMap[$memberId])) {
                $role = ClusterMemberRole::SUPPORT;
            }

            $localScore = $memberScores[$memberId] ?? null;

            $cluster->addClusterMember(new ClusterMember(
                $cluster,
                $mediaItem,
                $role,
                $index,
                $localScore,
            ));
        }
    }

    /**
     * @param mixed $values
     *
     * @return list<int>
     */
    private function normaliseMemberIdList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $id = null;
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $id = (int) $value;
            }

            if ($id === null || $id === 0) {
                continue;
            }

            if (!in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, float>
     */
    private function extractMemberScores(array $params): array
    {
        $memberQuality = $params['member_quality'] ?? null;
        if (!is_array($memberQuality)) {
            return [];
        }

        $details = $memberQuality['members'] ?? null;
        if (!is_array($details)) {
            return [];
        }

        $scores = [];
        foreach ($details as $memberId => $detail) {
            if (is_int($memberId)) {
                $id = $memberId;
            } elseif (is_string($memberId) && is_numeric($memberId)) {
                $id = (int) $memberId;
            } else {
                continue;
            }

            if (!is_array($detail)) {
                continue;
            }

            $score = $detail['score'] ?? ($detail['quality'] ?? null);
            if ($score === null) {
                continue;
            }

            if (!is_float($score) && !is_int($score) && !is_numeric($score)) {
                continue;
            }

            $scores[$id] = (float) $score;
        }

        return $scores;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveClusterType(ClusterDraft $draft, array $params): string
    {
        $candidates = [
            $params['type'] ?? null,
            $params['storyline'] ?? null,
            $params['group'] ?? null,
            $draft->getStoryline(),
            $draft->getAlgorithm(),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $value = trim($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'story';
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

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    private function curateDrafts(array $drafts): array
    {
        if ($drafts === []) {
            return [];
        }

        $curated = [];
        foreach ($drafts as $draft) {
            $curated[] = $this->memberSelection->curate($draft);
        }

        return $curated;
    }

    private function persistSelectionTelemetryOnDraft(ClusterDraft $draft): void
    {
        $params     = $draft->getParams();
        $memberQuality = $params['member_quality'] ?? null;
        if (!is_array($memberQuality)) {
            return;
        }

        $summary = $memberQuality['summary'] ?? [];
        if (!is_array($summary)) {
            $summary = [];
        }

        $ordered = $memberQuality['ordered'] ?? [];
        if (!is_array($ordered)) {
            $ordered = [];
        }

        $overlayCount = 0;
        foreach ($ordered as $value) {
            if (is_int($value)) {
                ++$overlayCount;

                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                ++$overlayCount;
            }
        }

        $persistedCount = count($draft->getMembers());

        $summary['members_persisted'] = $persistedCount;
        $summary['curated_overlay_count'] = $overlayCount;

        $selectionCounts = $summary['selection_counts'] ?? [];
        if (!is_array($selectionCounts)) {
            $selectionCounts = [];
        }

        $selectionCounts['raw'] = isset($selectionCounts['raw']) && is_numeric($selectionCounts['raw'])
            ? (int) $selectionCounts['raw']
            : $persistedCount;

        $selectionCounts['curated'] = isset($selectionCounts['curated']) && is_numeric($selectionCounts['curated'])
            ? (int) $selectionCounts['curated']
            : $overlayCount;

        $summary['selection_counts'] = $selectionCounts;

        $memberQuality['summary'] = $summary;
        $draft->setParam('member_quality', $memberQuality);

        $this->emitPersistenceMetrics($draft, $persistedCount, $overlayCount);
    }

    /**
     * @return list<int>
     */
    private function resolveOrderedMembers(ClusterDraft $draft): array
    {
        return $draft->getMembers();
    }

    private function emitPersistenceMetrics(ClusterDraft $draft, int $persistedCount, int $overlayCount): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('cluster_persistence', 'overlay', [
            'algorithm'           => $draft->getAlgorithm(),
            'storyline'           => $draft->getStoryline(),
            'members_persisted'   => $persistedCount,
            'curated_overlay_len' => $overlayCount,
        ]);
    }

    /**
     * @return array{0: ClusterDraft, 1: array{
     *     startAt:?DateTimeImmutable,
     *     endAt:?DateTimeImmutable,
     *     membersCount:int,
     *     photoCount:?int,
     *     videoCount:?int,
     *     cover:?Media,
     *     location:?Location,
     *     algorithmVersion:?string,
     *     configHash:?string,
     *     centroidLat:?float,
     *     centroidLon:?float,
     *     centroidCell7:?string
     * }}
     */
    private function rebuildDraftFromEntity(Cluster $cluster): array
    {
        $draft = $this->mapClusterToDraft($cluster);

        $curated = $this->memberSelection->curate($draft);
        $this->persistSelectionTelemetryOnDraft($curated);

        $context = $this->resolveDraftContext($curated);
        $media   = $this->hydrateMembers($context['members']);
        $metadata = $this->buildMetadata($curated, $context['members'], $media);

        return [$curated, $metadata];
    }

    private function mapClusterToDraft(Cluster $cluster): ClusterDraft
    {
        $params    = $cluster->getParams();
        $storyline = null;
        if (is_string($params['storyline'] ?? null) && $params['storyline'] !== '') {
            $storyline = $params['storyline'];
        }

        $draft = new ClusterDraft(
            $cluster->getAlgorithm(),
            $params,
            $cluster->getCentroid(),
            $cluster->getMembers(),
            $storyline,
        );

        $draft->setStartAt($cluster->getStartAt());
        $draft->setEndAt($cluster->getEndAt());
        $draft->setMembersCount($cluster->getMembersCount());
        $draft->setPhotoCount($cluster->getPhotoCount());
        $draft->setVideoCount($cluster->getVideoCount());
        $draft->setCoverMediaId($cluster->getCover()?->getId());
        $draft->setLocation($cluster->getLocation());
        $draft->setAlgorithmVersion($cluster->getAlgorithmVersion());
        $draft->setConfigHash($cluster->getConfigHash());
        $draft->setCentroidLat($cluster->getCentroidLat());
        $draft->setCentroidLon($cluster->getCentroidLon());
        $draft->setCentroidCell7($cluster->getCentroidCell7());

        return $draft;
    }

    /**
     * @return array{raw_count:int, curated_count:int, overlay_count:int}
     */
    private function extractSelectionSummary(ClusterDraft $draft): array
    {
        $params        = $draft->getParams();
        $memberQuality = $params['member_quality'] ?? [];
        if (!is_array($memberQuality)) {
            $memberQuality = [];
        }

        $summary = $memberQuality['summary'] ?? [];
        if (!is_array($summary)) {
            $summary = [];
        }

        $counts = $summary['selection_counts'] ?? [];
        if (!is_array($counts)) {
            $counts = [];
        }

        $rawCount = $counts['raw'] ?? count($draft->getMembers());
        if (!is_int($rawCount)) {
            $rawCount = (int) $rawCount;
        }

        $curatedCount = $counts['curated'] ?? ($summary['curated_overlay_count'] ?? $rawCount);
        if (!is_int($curatedCount)) {
            $curatedCount = (int) $curatedCount;
        }

        $overlayCount = $summary['curated_overlay_count'] ?? $curatedCount;
        if (!is_int($overlayCount)) {
            $overlayCount = (int) $overlayCount;
        }

        return [
            'raw_count'     => max(0, $rawCount),
            'curated_count' => max(0, $curatedCount),
            'overlay_count' => max(0, $overlayCount),
        ];
    }
}
