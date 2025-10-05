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
            101 => $this->buildMedia(
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
            102 => $this->buildMedia(
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
            103 => $this->buildMedia(
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
            201 => $this->buildMedia(
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
                phash64: '1001',
                dhash: 'unique-dhash',
                burstUuid: null,
            ),
            202 => $this->buildMedia(
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
                phash64: '2002',
                dhash: 'duplicate-dhash',
                burstUuid: 'burst-1',
            ),
            203 => $this->buildMedia(
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
                phash64: '2002',
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
        self::assertGreaterThan($members['203']['score'], $members['202']['score']);
    }

    #[Test]
    public function prefersPreAggregatedQualityScoresWhenAvailable(): void
    {
        $lookup = new InMemoryMediaLookup([
            301 => $this->buildMedia(
                id: 301,
                width: 1,
                height: 1,
                sharpness: 0.0,
                iso: 0,
                brightness: 0.0,
                contrast: 0.0,
                entropy: 0.0,
                colorfulness: 0.0,
                phash: null,
                dhash: null,
                burstUuid: null,
                qualityScore: 0.9,
                qualityExposure: 0.8,
                qualityNoise: 0.7,
            ),
            302 => $this->buildMedia(
                id: 302,
                width: 1,
                height: 1,
                sharpness: 0.0,
                iso: 0,
                brightness: 0.0,
                contrast: 0.0,
                entropy: 0.0,
                colorfulness: 0.0,
                phash: null,
                dhash: null,
                burstUuid: null,
                qualityScore: 0.6,
                qualityExposure: 0.6,
                qualityNoise: 0.5,
            ),
            303 => $this->buildMedia(
                id: 303,
                width: 1,
                height: 1,
                sharpness: 0.0,
                iso: 0,
                brightness: 0.0,
                contrast: 0.0,
                entropy: 0.0,
                colorfulness: 0.0,
                phash: null,
                dhash: null,
                burstUuid: null,
                qualityScore: 0.2,
                qualityExposure: 0.4,
                qualityNoise: 0.3,
            ),
        ]);

        $stage = new MemberQualityRankingStage($lookup, 12.0);

        $draft = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [301, 302, 303],
        );

        $stage->process([$draft]);

        $meta     = $draft->getParams()['member_quality'];
        $ordered  = $meta['quality_ranked']['ordered'];
        $members  = $meta['members'];

        self::assertSame([301, 302, 303], $ordered);
        self::assertSame(0.9, $members['301']['quality']);
        self::assertSame(0.6, $members['302']['quality']);
        self::assertSame(0.2, $members['303']['quality']);
    }

    #[Test]
    public function skipsMembersMarkedAsHidden(): void
    {
        $lookup = new InMemoryMediaLookup([
            401 => $this->buildMedia(
                id: 401,
                width: 4000,
                height: 3000,
                sharpness: 0.8,
                iso: 200,
                brightness: 0.55,
                contrast: 0.62,
                entropy: 0.58,
                colorfulness: 0.60,
                phash: 'visible-phash',
                dhash: 'visible-dhash',
                burstUuid: null,
            ),
            402 => $this->buildMedia(
                id: 402,
                width: 3000,
                height: 2000,
                sharpness: 0.7,
                iso: 160,
                brightness: 0.50,
                contrast: 0.58,
                entropy: 0.55,
                colorfulness: 0.52,
                phash: 'hidden-phash',
                dhash: 'hidden-dhash',
                burstUuid: null,
                noShow: true,
            ),
        ]);

        $stage = new MemberQualityRankingStage($lookup, 12.0);

        $draft = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [401, 402],
        );

        $stage->process([$draft]);

        $meta = $draft->getParams()['member_quality'];

        self::assertSame([401], $meta['ordered']);
        self::assertSame([401], $meta['quality_ranked']['ordered']);
        self::assertArrayHasKey('401', $meta['members']);
        self::assertArrayNotHasKey('402', $meta['members']);
    }

    private function buildMedia(
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
        ?string $phash64 = null,
        ?string $dhash = null,
        ?string $burstUuid = null,
        ?float $qualityScore = null,
        ?float $qualityExposure = null,
        ?float $qualityNoise = null,
        bool $lowQuality = false,
        bool $noShow = false,
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
        $media->setPhashPrefix($phash);
        if ($phash64 !== null) {
            $media->setPhash64($phash64);
        }
        $media->setDhash($dhash);
        $media->setBurstUuid($burstUuid);

        $media->setQualityScore($qualityScore);
        $media->setQualityExposure($qualityExposure);
        $media->setQualityNoise($qualityNoise);
        $media->setLowQuality($lowQuality);
        $media->setNoShow($noShow);

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
    public function __construct(private readonly array $map)
    {
    }

    public function findByIds(array $ids, bool $onlyVideos = false): array
    {
        $result = [];
        foreach ($ids as $id) {
            $media = $this->map[$id] ?? null;
            if ($media instanceof Media && $media->isNoShow() === false) {
                $result[] = $media;
            }
        }

        return $result;
    }
}
