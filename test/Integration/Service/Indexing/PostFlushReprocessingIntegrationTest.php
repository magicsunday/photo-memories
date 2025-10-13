<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Service\Indexing;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Entity\MediaDuplicate;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\DefaultMediaIngestionPipeline;
use MagicSunday\Memories\Service\Indexing\Stage\NearDuplicateStage;
use MagicSunday\Memories\Service\Indexing\Stage\PersistenceBatchStage;
use MagicSunday\Memories\Service\Indexing\Stage\PostFlushReprocessingStage;
use MagicSunday\Memories\Service\Indexing\Support\PersistedMediaTracker;
use MagicSunday\Memories\Service\Metadata\BurstDetector;
use MagicSunday\Memories\Service\Metadata\LivePairLinker;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;
use MagicSunday\Memories\Repository\MediaDuplicateRepository;
use ArrayObject;
use function array_unique;
use function array_values;
use function iterator_to_array;

final class PostFlushReprocessingIntegrationTest extends TestCase
{
    #[Test]
    public function itLinksBurstMembersAfterSingleRun(): void
    {
        $repository          = new InMemoryMediaRepository($this->createStub(EntityManagerInterface::class));
        $tracker             = new PersistedMediaTracker();
        $duplicateRepository = new RecordingMediaDuplicateRepository();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->configureEntityManager($entityManager, $repository);

        $nearDuplicateStage = new NearDuplicateStage($repository, $duplicateRepository, 6);
        $burstDetector      = new BurstDetector($repository);
        $livePairLinker     = new LivePairLinker($repository);

        $pipeline = new DefaultMediaIngestionPipeline([
            new FixtureSeedStage([
                '/burst-a.jpg' => fn (): Media => $this->makeBurstCandidate('/burst-a.jpg', 'aaaa', '2024-05-04T10:00:00+00:00'),
                '/burst-b.jpg' => fn (): Media => $this->makeBurstCandidate('/burst-b.jpg', 'bbbb', '2024-05-04T10:00:01+00:00'),
            ]),
            new PersistenceBatchStage($entityManager, 10, $tracker),
            new PostFlushReprocessingStage(
                $tracker,
                $repository,
                $nearDuplicateStage,
                $burstDetector,
                $livePairLinker,
                $entityManager,
            ),
        ]);

        $this->runPipeline($pipeline, ['/burst-a.jpg', '/burst-b.jpg']);

        $stored = $repository->all();
        self::assertCount(2, $stored);

        $burstUuid = $stored[0]->getBurstUuid();
        self::assertNotNull($burstUuid);
        foreach ($stored as $media) {
            self::assertSame($burstUuid, $media->getBurstUuid());
            self::assertNotNull($media->getBurstIndex());
        }

        $indices = array_map(static fn (Media $media): int => (int) $media->getBurstIndex(), $stored);
        sort($indices);
        self::assertSame([0, 1], $indices);

        $representatives = array_map(static fn (Media $media): bool => (bool) $media->isBurstRepresentative(), $stored);
        self::assertContains(true, $representatives);
        self::assertContains(false, $representatives);

        self::assertNotEmpty($duplicateRepository->records);
    }

    #[Test]
    public function itLinksLivePairsAfterSingleRun(): void
    {
        $repository          = new InMemoryMediaRepository($this->createStub(EntityManagerInterface::class));
        $tracker             = new PersistedMediaTracker();
        $duplicateRepository = new RecordingMediaDuplicateRepository();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->configureEntityManager($entityManager, $repository);

        $nearDuplicateStage = new NearDuplicateStage($repository, $duplicateRepository, 6);
        $burstDetector      = new BurstDetector($repository);
        $livePairLinker     = new LivePairLinker($repository);

        $pipeline = new DefaultMediaIngestionPipeline([
            new FixtureSeedStage([
                '/live-photo.jpg' => fn (): Media => $this->makeLivePairPhoto('/live-photo.jpg'),
                '/live-video.mov' => fn (): Media => $this->makeLivePairVideo('/live-video.mov'),
            ]),
            new PersistenceBatchStage($entityManager, 10, $tracker),
            new PostFlushReprocessingStage(
                $tracker,
                $repository,
                $nearDuplicateStage,
                $burstDetector,
                $livePairLinker,
                $entityManager,
            ),
        ]);

        $this->runPipeline($pipeline, ['/live-photo.jpg', '/live-video.mov']);

        $stored = $repository->all();
        self::assertCount(2, $stored);

        usort($stored, static fn (Media $left, Media $right): int => $left->getPath() <=> $right->getPath());

        $photo = $stored[0];
        $video = $stored[1];

        self::assertSame($video, $photo->getLivePairMedia());
        self::assertSame($photo, $video->getLivePairMedia());
        self::assertNotNull($photo->getLivePairChecksum());
        self::assertSame($photo->getLivePairChecksum(), $video->getLivePairChecksum());
    }

    private function runPipeline(DefaultMediaIngestionPipeline $pipeline, array $files): void
    {
        $output = new NullOutput();
        foreach ($files as $file) {
            $pipeline->process($file, false, false, false, false, $output);
        }

        $pipeline->finalize(false);
    }

    private function configureEntityManager(EntityManagerInterface $entityManager, InMemoryMediaRepository $repository): void
    {
        $pending  = [];
        $nextId   = 1;
        $assigned = [];

        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$pending): void {
                $pending[spl_object_id($entity)] = $entity;
            }
        );

        $entityManager->method('flush')->willReturnCallback(
            function (?object $entity = null) use (&$pending, &$nextId, &$assigned, $repository): void {
                $targets = [];

                if ($entity instanceof Media) {
                    $targets = [$entity];
                } elseif ($entity === null) {
                    $targets = array_values($pending);
                }

                foreach ($targets as $target) {
                    if (!$target instanceof Media) {
                        continue;
                    }

                    $objectId = spl_object_id($target);
                    if (!isset($assigned[$objectId])) {
                        $this->assignId($target, $nextId++);
                        $assigned[$objectId] = true;
                    }

                    $repository->store($target);
                    unset($pending[spl_object_id($target)]);
                }
            }
        );

        $entityManager->method('detach')->willReturnCallback(
            static function (): void {
            }
        );
    }

    private function makeBurstCandidate(string $path, string $checksumSeed, string $timestamp): Media
    {
        $media = new Media($path, str_repeat($checksumSeed, 16), 2048);
        $media->setTakenAt(new DateTimeImmutable($timestamp));
        $media->setMime('image/jpeg');
        $media->setIsVideo(false);
        $media->setPhash(str_repeat('a', 32));

        return $media;
    }

    private function makeLivePairPhoto(string $path): Media
    {
        $media = new Media($path, str_repeat('c', 64), 4096);
        $media->setTakenAt(new DateTimeImmutable('2024-05-04T11:00:00+00:00'));
        $media->setMime('image/jpeg');
        $media->setIsVideo(false);
        $media->setPhash(str_repeat('b', 32));
        $media->setLivePairChecksum('live-pair-001');

        return $media;
    }

    private function makeLivePairVideo(string $path): Media
    {
        $media = new Media($path, str_repeat('d', 64), 5120);
        $media->setTakenAt(new DateTimeImmutable('2024-05-04T11:00:01+00:00'));
        $media->setMime('video/quicktime');
        $media->setIsVideo(true);
        $media->setPhash(str_repeat('b', 32));
        $media->setLivePairChecksum('live-pair-001');

        return $media;
    }
}

/**
 * @extends MediaRepository
 */
final readonly class InMemoryMediaRepository extends MediaRepository
{
    /**
     * @var ArrayObject<int, Media>
     */
    private ArrayObject $storage;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
        $this->storage = new ArrayObject();
    }

    public function store(Media $media): void
    {
        $this->storage[$media->getId()] = $media;
    }

    /**
     * @return list<Media>
     */
    public function all(): array
    {
        return array_values(iterator_to_array($this->storage));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Media>
     */
    public function findByIds(array $ids, bool $onlyVideos = false): array
    {
        $result = [];
        foreach (array_unique($ids) as $id) {
            $media = $this->storage[$id] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            if ($onlyVideos && !$media->isVideo()) {
                continue;
            }

            $result[] = $media;
        }

        return $result;
    }

    public function findNearestByPhash(string $phashHex, int $maxHamming, int $limit = 20): array
    {
        $phashHex = strtolower(trim($phashHex));
        if ($phashHex === '' || $maxHamming < 0) {
            return [];
        }

        $candidates = [];
        foreach ($this->storage as $media) {
            $otherPhash = $media->getPhash();
            if ($otherPhash === null || $otherPhash === '') {
                continue;
            }

            $distance = $this->calculateHammingDistance($phashHex, strtolower($otherPhash));
            if ($distance > $maxHamming) {
                continue;
            }

            $candidates[] = [
                'media'    => $media,
                'distance' => $distance,
            ];
        }

        usort(
            $candidates,
            static function (array $left, array $right): int {
                $distanceComparison = $left['distance'] <=> $right['distance'];
                if ($distanceComparison !== 0) {
                    return $distanceComparison;
                }

                /** @var Media $leftMedia */
                $leftMedia  = $left['media'];
                /** @var Media $rightMedia */
                $rightMedia = $right['media'];

                return $leftMedia->getId() <=> $rightMedia->getId();
            }
        );

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @return list<Media>
     */
    public function findBurstMembers(string $burstUuid, ?string $excludePath = null): array
    {
        $burstUuid = trim($burstUuid);
        if ($burstUuid === '') {
            return [];
        }

        $items = [];
        foreach ($this->storage as $media) {
            if ($media->getBurstUuid() !== $burstUuid) {
                continue;
            }

            if ($excludePath !== null && $media->getPath() === $excludePath) {
                continue;
            }

            $items[] = $media;
        }

        usort(
            $items,
            static function (Media $left, Media $right): int {
                $leftIndex  = $left->getBurstIndex();
                $rightIndex = $right->getBurstIndex();

                if ($leftIndex !== null && $rightIndex !== null && $leftIndex !== $rightIndex) {
                    return $leftIndex <=> $rightIndex;
                }

                $leftTaken  = $left->getTakenAt();
                $rightTaken = $right->getTakenAt();

                if ($leftTaken instanceof DateTimeImmutable && $rightTaken instanceof DateTimeImmutable) {
                    $comparison = $leftTaken->getTimestamp() <=> $rightTaken->getTimestamp();
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return $left->getId() <=> $right->getId();
            }
        );

        return $items;
    }

    public function findLivePairCandidate(string $checksum, string $path): ?Media
    {
        $checksum = trim($checksum);
        $path     = trim($path);

        if ($checksum === '' || $path === '') {
            return null;
        }

        foreach ($this->storage as $media) {
            if ($media->getPath() === $path) {
                continue;
            }

            if ($media->getLivePairChecksum() !== $checksum) {
                continue;
            }

            if ($media->isNoShow()) {
                continue;
            }

            return $media;
        }

        return null;
    }

    private function calculateHammingDistance(string $left, string $right): int
    {
        $length = max(strlen($left), strlen($right));
        $left   = str_pad($left, $length, '0');
        $right  = str_pad($right, $length, '0');

        $distance = 0;
        for ($index = 0; $index < $length; ++$index) {
            $leftNibble  = (int) base_convert($left[$index], 16, 10);
            $rightNibble = (int) base_convert($right[$index], 16, 10);
            $distance   += $this->bitCount($leftNibble ^ $rightNibble);
        }

        return $distance;
    }

    private function bitCount(int $value): int
    {
        $count = 0;
        while ($value > 0) {
            $count += $value & 1;
            $value >>= 1;
        }

        return $count;
    }
}

final class FixtureSeedStage implements MediaIngestionStageInterface
{
    /**
     * @param array<string, callable(): Media> $factories
     */
    public function __construct(private readonly array $factories)
    {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        $factory = $this->factories[$context->getFilePath()] ?? null;
        if ($factory === null) {
            return $context;
        }

        $media = $factory();

        return $context->withMedia($media);
    }
}

final readonly class RecordingMediaDuplicateRepository extends MediaDuplicateRepository
{
    /**
     * @var ArrayObject<int, array{Media, Media, int}>
     */
    public ArrayObject $records;

    public function __construct()
    {
        $this->records = new ArrayObject();
    }

    public function recordDistance(Media $first, Media $second, int $distance): MediaDuplicate
    {
        $this->records[] = [$first, $second, $distance];

        return new MediaDuplicate($first, $second, $distance);
    }
}
