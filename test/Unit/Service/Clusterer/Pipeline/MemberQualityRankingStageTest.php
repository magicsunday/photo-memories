<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberQualityRankingStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

final class MemberQualityRankingStageTest extends TestCase
{
    #[Test]
    public function sortsMembersByComputedQualityScore(): void
    {
        $lookup = new InMemoryMediaLookup([
            101 => $this->makeMedia(
                id: 101,
                width: 6000,
                height: 4000,
                sharpness: 0.92,
                iso: 100,
                brightness: 0.58,
                contrast: 0.72,
                entropy: 0.70,
                colorfulness: 0.75,
                phash: 'p-high',
                dhash: 'd-high',
                burstUuid: null,
            ),
            102 => $this->makeMedia(
                id: 102,
                width: 3200,
                height: 2400,
                sharpness: 0.68,
                iso: 200,
                brightness: 0.54,
                contrast: 0.60,
                entropy: 0.58,
                colorfulness: 0.62,
                phash: 'p-mid',
                dhash: 'd-mid',
                burstUuid: null,
            ),
            103 => $this->makeMedia(
                id: 103,
                width: 1600,
                height: 1200,
                sharpness: 0.40,
                iso: 400,
                brightness: 0.42,
                contrast: 0.46,
                entropy: 0.44,
                colorfulness: 0.40,
                phash: 'p-low',
                dhash: 'd-low',
                burstUuid: null,
            ),
        ]);

        $stage = new MemberQualityRankingStage($lookup, 12.0);

        $draft = new ClusterDraft(
            algorithm: 'test',
            params: [
                'quality_avg'        => 0.55,
                'aesthetics_score'   => 0.52,
                'quality_resolution' => 0.60,
                'quality_sharpness'  => 0.58,
                'quality_iso'        => 0.65,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [101, 102, 103],
        );

        $result = $stage->process([$draft]);
        self::assertCount(1, $result);

        $params = $draft->getParams();
        self::assertArrayHasKey('member_quality', $params);

        /** @var array{ordered:list<int>,quality_ranked:array{ordered:list<int>,members:list<array{id:int,score:float,quality:float,aesthetics:float,penalty:float}>},members:array<string,array{score:float,quality:float,aesthetics:float,penalty:float}>} $meta */
        $meta = $params['member_quality'];

        self::assertSame([101, 102, 103], $meta['ordered']);
        self::assertSame([101, 102, 103], $meta['quality_ranked']['ordered']);
        self::assertSame([101, 102, 103], array_map(static fn (array $entry): int => $entry['id'], $meta['quality_ranked']['members']));

        $members = $meta['members'];
        self::assertGreaterThan($members['102']['score'], $members['101']['score']);
        self::assertGreaterThan($members['103']['score'], $members['102']['score']);
        self::assertSame(0.0, $members['101']['penalty']);
    }

    #[Test]
    public function penalisesDuplicateHashesWithinCluster(): void
    {
        $lookup = new InMemoryMediaLookup([
            201 => $this->makeMedia(
                id: 201,
                width: 5400,
                height: 3600,
                sharpness: 0.85,
                iso: 120,
                brightness: 0.57,
                contrast: 0.66,
                entropy: 0.63,
                colorfulness: 0.68,
                phash: 'unique-phash',
                dhash: 'unique-dhash',
                burstUuid: null,
            ),
            202 => $this->makeMedia(
                id: 202,
                width: 4800,
                height: 3200,
                sharpness: 0.82,
                iso: 160,
                brightness: 0.55,
                contrast: 0.64,
                entropy: 0.60,
                colorfulness: 0.66,
                phash: 'duplicate-phash',
                dhash: 'duplicate-dhash',
                burstUuid: 'burst-1',
            ),
            203 => $this->makeMedia(
                id: 203,
                width: 4800,
                height: 3200,
                sharpness: 0.82,
                iso: 160,
                brightness: 0.55,
                contrast: 0.64,
                entropy: 0.60,
                colorfulness: 0.66,
                phash: 'duplicate-phash',
                dhash: 'duplicate-dhash',
                burstUuid: 'burst-1',
            ),
        ]);

        $stage = new MemberQualityRankingStage($lookup, 12.0);

        $draft = new ClusterDraft(
            algorithm: 'test',
            params: [
                'quality_avg'        => 0.58,
                'aesthetics_score'   => 0.54,
                'quality_resolution' => 0.62,
                'quality_sharpness'  => 0.60,
                'quality_iso'        => 0.66,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [201, 202, 203],
        );

        $stage->process([$draft]);

        $meta    = $draft->getParams()['member_quality'];
        $members = $meta['members'];

        self::assertSame([201, 202, 203], $meta['ordered']);
        self::assertSame([201, 202, 203], $meta['quality_ranked']['ordered']);
        self::assertSame(0.0, $members['202']['penalty']);
        self::assertGreaterThan($members['202']['penalty'], $members['203']['penalty']);
        self::assertGreaterThan($members['202']['score'], $members['203']['score']);
    }

    private function makeMedia(
        int $id,
        int $width,
        int $height,
        float $sharpness,
        int $iso,
        float $brightness,
        float $contrast,
        float $entropy,
        float $colorfulness,
        ?string $phash,
        ?string $dhash,
        ?string $burstUuid,
    ): Media {
        $media = new Media(path: 'media-' . $id . '.jpg', checksum: 'checksum-' . $id, size: 1024);

        $ref = new ReflectionProperty(Media::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($media, $id);

        $media->setWidth($width);
        $media->setHeight($height);
        $media->setSharpness($sharpness);
        $media->setIso($iso);
        $media->setBrightness($brightness);
        $media->setContrast($contrast);
        $media->setEntropy($entropy);
        $media->setColorfulness($colorfulness);
        $media->setPhash($phash);
        $media->setDhash($dhash);
        $media->setBurstUuid($burstUuid);

        return $media;
    }
}

/**
 * @internal
 */
final class InMemoryMediaLookup implements MemberMediaLookupInterface
{
    /**
     * @param array<int, Media> $map
     */
    public function __construct(private array $map)
    {
    }

    public function findByIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $media = $this->map[$id] ?? null;
            if ($media instanceof Media) {
                $result[] = $media;
            }
        }

        return $result;
    }
}
