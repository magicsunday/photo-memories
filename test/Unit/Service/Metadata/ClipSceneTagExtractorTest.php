<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\ClipSceneTagExtractor;
use MagicSunday\Memories\Service\Metadata\HeuristicClipSceneTagModel;
use MagicSunday\Memories\Service\Metadata\VisionSceneTagModelInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClipSceneTagExtractorTest extends TestCase
{
    #[Test]
    public function extractStoresTopSceneTags(): void
    {
        $media = new Media('scene.jpg', 'checksum-scene', 2048);
        $media->setMime('image/jpeg');
        $media->setFeatures([
            'season'    => 'summer',
            'isHoliday' => true,
        ]);
        $media->setPersons(['Anna', 'Ben']);
        $media->setHasFaces(true);
        $media->setFacesCount(2);
        $media->setGpsLat(48.1);
        $media->setGpsLon(11.5);
        $media->setBrightness(0.82);
        $media->setSharpness(0.65);
        $media->setWidth(4000);
        $media->setHeight(2000);
        $media->setKeywords(['beach', 'family']);

        $extractor = new ClipSceneTagExtractor(new HeuristicClipSceneTagModel(), maxTags: 3, minScore: 0.3);

        $result = $extractor->extract('/tmp/scene.jpg', $media);

        $tags = $result->getSceneTags();
        self::assertIsArray($tags);
        self::assertCount(3, $tags);
        self::assertSame('Porträt', $tags[0]['label']);
        self::assertEqualsWithDelta(0.76, $tags[0]['score'], 0.0001);
        self::assertSame('Strand', $tags[1]['label']);
        self::assertEqualsWithDelta(0.74, $tags[1]['score'], 0.0001);
        self::assertSame('Outdoor', $tags[2]['label']);
        self::assertEqualsWithDelta(0.72, $tags[2]['score'], 0.0001);

        self::assertSame('scene=Porträt(0.76),Strand(0.74),Outdoor(0.72)', $result->getIndexLog());

        $features = $result->getFeatures();
        self::assertIsArray($features);
        self::assertSame('summer', $features['season']);
    }

    #[Test]
    public function supportsOnlyMediaPayloads(): void
    {
        $extractor = new ClipSceneTagExtractor(new HeuristicClipSceneTagModel());

        $image = new Media('a.jpg', 'a', 100);
        $image->setMime('image/png');
        self::assertTrue($extractor->supports('a.jpg', $image));

        $video = new Media('b.mp4', 'b', 100);
        $video->setMime('video/mp4');
        self::assertTrue($extractor->supports('b.mp4', $video));

        $doc = new Media('c.pdf', 'c', 100);
        $doc->setMime('application/pdf');
        self::assertFalse($extractor->supports('c.pdf', $doc));
    }

    #[Test]
    public function supportsSkipsNoShowMedia(): void
    {
        $extractor = new ClipSceneTagExtractor(new HeuristicClipSceneTagModel());

        $media = new Media('hidden.jpg', 'hidden', 128);
        $media->setMime('image/jpeg');
        $media->setNoShow(true);

        self::assertFalse($extractor->supports('hidden.jpg', $media));
    }

    #[Test]
    public function extractClearsTagsWhenScoresAreTooLow(): void
    {
        $model = new class implements VisionSceneTagModelInterface {
            public function predict(string $filepath, Media $media): array
            {
                return ['unsure' => 0.05];
            }
        };

        $extractor = new ClipSceneTagExtractor($model, maxTags: 2, minScore: 0.3);

        $media = new Media('d.jpg', 'd', 100);
        $media->setMime('image/jpeg');
        $media->setSceneTags([
            ['label' => 'Alt', 'score' => 0.9],
        ]);

        $result = $extractor->extract('d.jpg', $media);

        self::assertNull($result->getSceneTags());
    }

    #[Test]
    public function extractAppendsSceneSummaryToExistingLog(): void
    {
        $model = new class implements VisionSceneTagModelInterface {
            public function predict(string $filepath, Media $media): array
            {
                return [
                    'beach'  => 0.82,
                    'sunset' => 0.64,
                ];
            }
        };

        $media = new Media('scene2.jpg', 'checksum-scene2', 2048);
        $media->setMime('image/jpeg');
        $media->setIndexLog('time=exif; tz=UTC; off=+0');

        $extractor = new ClipSceneTagExtractor($model, maxTags: 2, minScore: 0.3);
        $result    = $extractor->extract('/tmp/scene2.jpg', $media);

        self::assertSame(
            "time=exif; tz=UTC; off=+0\nscene=beach(0.82),sunset(0.64)",
            $result->getIndexLog()
        );
    }

    #[Test]
    public function extractAcceptsZeroScoreWhenMinimumScoreIsZero(): void
    {
        $model = new class implements VisionSceneTagModelInterface {
            public function predict(string $filepath, Media $media): array
            {
                return [
                    'neutral'  => 0.0,
                    'negative' => -0.2,
                    'positive' => 0.5,
                ];
            }
        };

        $media = new Media('scene3.jpg', 'checksum-scene3', 1024);
        $media->setMime('image/jpeg');

        $extractor = new ClipSceneTagExtractor($model, maxTags: 3, minScore: 0.0);
        $result    = $extractor->extract('/tmp/scene3.jpg', $media);

        $tags = $result->getSceneTags();
        self::assertIsArray($tags);
        self::assertCount(2, $tags);
        self::assertSame('positive', $tags[0]['label']);
        self::assertEqualsWithDelta(0.5, $tags[0]['score'], 0.0001);
        self::assertSame('neutral', $tags[1]['label']);
        self::assertEqualsWithDelta(0.0, $tags[1]['score'], 0.0001);
    }
}
