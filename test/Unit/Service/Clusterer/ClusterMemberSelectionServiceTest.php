<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Selection\MemberSelectorInterface;
use MagicSunday\Memories\Clusterer\Selection\SelectionResult;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterMemberSelectionProfileProvider;
use MagicSunday\Memories\Service\Clusterer\ClusterMemberSelectionService;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClusterMemberSelectionServiceTest extends TestCase
{
    #[Test]
    public function curateReordersMembersAndUpdatesMetadata(): void
    {
        $base = new DateTimeImmutable('2024-05-20 10:00:00');

        $media1 = $this->createMedia(1, $base, false, '0a0a');
        $media2 = $this->createMedia(2, $base->add(new DateInterval('PT1H')), false, '0b0b');
        $media3 = $this->createMedia(3, $base->add(new DateInterval('P1D')), true, '0c0c');

        $lookup = new class([$media1, $media2, $media3]) implements MemberMediaLookupInterface {
            /**
             * @param list<Media> $media
             */
            public function __construct(private readonly array $media)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $result = [];
                foreach ($ids as $id) {
                    $index = (int) $id - 1;
                    if (isset($this->media[$index])) {
                        $result[] = $this->media[$index];
                    }
                }

                return $result;
            }
        };

        $selector = $this->createMock(MemberSelectorInterface::class);
        $selector->expects(self::once())
            ->method('select')
            ->willReturnCallback(static function (array $daySummaries) use ($media1, $media3): SelectionResult {
                self::assertArrayHasKey('2024-05-20', $daySummaries);
                self::assertArrayHasKey('2024-05-21', $daySummaries);

                return new SelectionResult([$media1, $media3], ['near_duplicate_blocked' => 1]);
            });

        $profileProvider = new SelectionProfileProvider(new VacationSelectionOptions());
        $provider        = new ClusterMemberSelectionProfileProvider($profileProvider);

        $service = new ClusterMemberSelectionService($selector, $lookup, $provider);

        $draft = new ClusterDraft('demo', ['foo' => 'bar'], ['lat' => 48.1, 'lon' => 11.5], [1, 2, 3]);

        $curated = $service->curate($draft);

        self::assertNotSame($draft, $curated);
        self::assertSame([1, 3], $curated->getMembers());
        self::assertSame(2, $curated->getMembersCount());
        self::assertSame(1, $curated->getPhotoCount());
        self::assertSame(1, $curated->getVideoCount());

        $selection = $curated->getParams()['member_selection'] ?? [];
        self::assertIsArray($selection);
        self::assertSame('default', $selection['profile']);
        self::assertSame(['pre' => 3, 'post' => 2, 'dropped' => 1], $selection['counts']);
        self::assertArrayHasKey('telemetry', $selection);
        self::assertSame(1, $selection['telemetry']['near_duplicate_blocked']);
        self::assertArrayHasKey('per_day_distribution', $selection);
        self::assertSame(['2024-05-20' => 1, '2024-05-21' => 1], $selection['per_day_distribution']);
        self::assertArrayHasKey('spacing', $selection);
        self::assertGreaterThan(0.0, $selection['spacing']['average_seconds']);
        self::assertArrayHasKey('hash_samples', $selection);
        self::assertCount(2, $selection['hash_samples']);
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, bool $isVideo, string $phash): Media
    {
        $media = new Media('path-' . $id . '.jpg', 'checksum-' . $id, 1024);
        $this->assignEntityId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setIsVideo($isVideo);
        $media->setPhash($phash);
        $media->setWidth(4000);
        $media->setHeight(3000);

        return $media;
    }
}
