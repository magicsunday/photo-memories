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
use MagicSunday\Memories\Clusterer\Contract\StaypointDetectorInterface;
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
use ReflectionProperty;

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

        $detector = $this->createMock(StaypointDetectorInterface::class);
        $detector->expects(self::never())
            ->method('detect');

        $profileProvider = new SelectionProfileProvider(new VacationSelectionOptions());
        $provider        = new ClusterMemberSelectionProfileProvider($profileProvider);

        $service = new ClusterMemberSelectionService($selector, $lookup, $provider, $detector);

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
        self::assertSame($selection['counts'], $selection['telemetry']['counts']);
        self::assertArrayHasKey('averages', $selection['telemetry']);
        self::assertArrayHasKey('relaxation_hints', $selection['telemetry']);
        self::assertArrayHasKey('per_day_distribution', $selection);
        self::assertSame(['2024-05-20' => 1, '2024-05-21' => 1], $selection['per_day_distribution']);
        self::assertArrayHasKey('per_bucket_distribution', $selection);
        self::assertArrayHasKey('spacing', $selection);
        self::assertGreaterThan(0.0, $selection['spacing']['average_seconds']);
        self::assertArrayHasKey('rejections', $selection['spacing']);
        self::assertSame(0, $selection['spacing']['rejections']);
        self::assertArrayHasKey('near_duplicates', $selection);
        self::assertSame(['blocked' => 1, 'replacements' => 0], $selection['near_duplicates']);
        self::assertArrayHasKey('hash_samples', $selection);
        self::assertCount(2, $selection['hash_samples']);
        self::assertSame(
            $selection['per_day_distribution'],
            $selection['telemetry']['distribution']['per_day'],
        );
    }

    #[Test]
    public function curatePopulatesStaypointsAndSortsGpsMembers(): void
    {
        $base = new DateTimeImmutable('2024-07-10 08:00:00');

        $first  = $this->createMedia(1, $base->add(new DateInterval('PT90M')), false, '1a1a', 48.1371, 11.5753);
        $second = $this->createMedia(2, $base, false, '2b2b', 48.1372, 11.5754);
        $third  = $this->createMedia(3, $base->add(new DateInterval('P1D')), true, '3c3c');

        $lookup = new class([$first, $second, $third]) implements MemberMediaLookupInterface {
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

        $staypoints = [[
            'lat'   => 48.13715,
            'lon'   => 11.57535,
            'start' => $base->getTimestamp(),
            'end'   => $base->add(new DateInterval('PT90M'))->getTimestamp(),
            'dwell' => 5400,
        ]];

        $detector = $this->createMock(StaypointDetectorInterface::class);
        $detector->expects(self::once())
            ->method('detect')
            ->with(self::callback(static function (array $gpsMembers) use ($second, $first): bool {
                self::assertSame([$second, $first], $gpsMembers);

                return true;
            }))
            ->willReturn($staypoints);

        $selector = $this->createMock(MemberSelectorInterface::class);
        $selector->expects(self::once())
            ->method('select')
            ->willReturnCallback(static function (array $daySummaries) use ($staypoints, $first, $second): SelectionResult {
                $summary = $daySummaries['2024-07-10'] ?? null;
                self::assertNotNull($summary);
                self::assertSame($staypoints, $summary['staypoints']);
                self::assertSame([$second, $first], $summary['gpsMembers']);

                return new SelectionResult([$first, $second], []);
            });

        $profileProvider = new SelectionProfileProvider(new VacationSelectionOptions());
        $provider        = new ClusterMemberSelectionProfileProvider($profileProvider);

        $service = new ClusterMemberSelectionService($selector, $lookup, $provider, $detector);

        $draft   = new ClusterDraft('staypoint-demo', [], ['lat' => 48.137, 'lon' => 11.575], [2, 1, 3]);
        $service->curate($draft);

        $reflection = new ReflectionProperty($service, 'daySummaries');
        $reflection->setAccessible(true);
        $daySummaries = $reflection->getValue($service);

        self::assertIsArray($daySummaries);
        self::assertSame($staypoints, $daySummaries['2024-07-10']['staypoints']);
    }

    private function createMedia(
        int $id,
        DateTimeImmutable $takenAt,
        bool $isVideo,
        string $phash,
        ?float $lat = null,
        ?float $lon = null,
    ): Media
    {
        $media = new Media('path-' . $id . '.jpg', 'checksum-' . $id, 1024);
        $this->assignEntityId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setIsVideo($isVideo);
        $media->setPhash($phash);
        $media->setWidth(4000);
        $media->setHeight(3000);
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);

        return $media;
    }
}
