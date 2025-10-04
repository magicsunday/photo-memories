<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Clusterer\TitleGeneratorInterface;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Service\Feed\MemoryFeedBuilder;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

final class MemoryFeedBuilderTest extends TestCase
{
    #[Test]
    public function filtersHiddenMediaFromFeedItems(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $visible = $this->buildMedia(1, false);
        $hidden  = $this->buildMedia(2, true);

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->expects(self::once())
            ->method('findByIds')
            ->with([1, 2], false)
            ->willReturn([$visible, $hidden]);

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            minScore: 0.0,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'score' => 0.5,
                'time_range' => ['to' => 1],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $result = $builder->build([$cluster]);

        self::assertCount(1, $result);
        $item = $result[0];

        self::assertSame([1], $item->getMemberIds());
        self::assertSame(1, $item->getCoverMediaId());
    }

    private function buildMedia(int $id, bool $noShow): Media
    {
        $media = new Media('path-' . $id . '.jpg', 'checksum-' . $id, 1024);

        $ref = new ReflectionProperty(Media::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($media, $id);

        $media->setNoShow($noShow);

        return $media;
    }
}
