<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\GeoStage;
use MagicSunday\Memories\Service\Metadata\Contract\StaypointCandidateProviderInterface;
use MagicSunday\Memories\Service\Metadata\GeoFeatureEnricher;
use MagicSunday\Memories\Service\Metadata\StaypointPlaceHeuristic;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\S2CellId;
use MagicSunday\Memories\Value\PlaceId;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
final class GeoStageStaypointIntegrationTest extends TestCase
{
    #[Test]
    public function stageAssignsPlaceIdFromStaypoints(): void
    {
        $media = $this->makeMedia(
            id: 101,
            path: '/library/stage-stay.jpg',
            takenAt: '2024-09-01 16:00:00',
            lat: 48.1901,
            lon: 11.6304,
        );

        $output  = new BufferedOutput();
        $context = MediaIngestionContext::create(
            $media->getPath(),
            false,
            false,
            false,
            false,
            $output,
        )->withMedia($media);

        $geo         = new GeoFeatureEnricher(48.137154, 11.576124, 1.0, 'v1', 0.01, 12);
        $candidates   = new IntegrationStaypointProvider([
            $this->makeMedia(
                id: 102,
                path: '/library/stage-stay-1.jpg',
                takenAt: '2024-09-01 16:30:00',
                lat: 48.1902,
                lon: 11.6305,
            ),
            $this->makeMedia(
                id: 103,
                path: '/library/stage-stay-2.jpg',
                takenAt: '2024-09-01 17:05:00',
                lat: 48.1903,
                lon: 11.6306,
            ),
        ]);
        $staypoint    = new StaypointPlaceHeuristic($candidates, minSamples: 3, minDurationMinutes: 60, maxSamples: 50, s2Level: 12);
        $stage        = new GeoStage($geo, $staypoint);

        $result = $stage->process($context);

        $updated = $result->getMedia();
        self::assertInstanceOf(Media::class, $updated);

        $placeId = $updated->getPlaceId();
        self::assertInstanceOf(PlaceId::class, $placeId);
        self::assertSame('staypoint:s2', $placeId->provider);
        self::assertSame(S2CellId::tokenFromDegrees(48.1901, 11.6304, 12), $placeId->identifier);

        $meta = $placeId->meta;
        self::assertSame(3, $meta['samples']);
        self::assertSame(65, $meta['durationMinutes']);
    }
}

/**
 * @internal
 */
final class IntegrationStaypointProvider implements StaypointCandidateProviderInterface
{
    /**
     * @param list<Media> $items
     */
    public function __construct(private array $items)
    {
    }

    public function findCandidates(Media $seed, int $maxSamples = 500): array
    {
        return $this->items;
    }
}
