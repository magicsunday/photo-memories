<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Scoring;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\NoveltyHeuristic;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class NoveltyHeuristicTest extends TestCase
{
    #[Test]
    public function timeRaritySamplesAllDaysCoveredByTimeRange(): void
    {
        $heuristic = new NoveltyHeuristic(
            weights: [
                'place'   => 0.0,
                'time'    => 1.0,
                'device'  => 0.0,
                'content' => 0.0,
            ],
        );

        $from = (new DateTimeImmutable('2024-03-10 23:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $to   = (new DateTimeImmutable('2024-03-11 01:00:00', new DateTimeZone('UTC')))->getTimestamp();

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'time_range' => ['from' => $from, 'to' => $to],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $mediaMap = [
            1 => $this->createMedia(1, '2024-03-10 23:00:00'),
            2 => $this->createMedia(2, '2024-03-11 01:00:00'),
        ];

        for ($i = 0; $i < 9; $i++) {
            $id = 100 + $i;
            $mediaMap[$id] = $this->createMedia($id, '2024-03-10 12:00:00');
        }

        $stats = $heuristic->buildCorpusStats($mediaMap);

        $score = $heuristic->computeNovelty($cluster, $mediaMap, $stats);

        self::assertEqualsWithDelta(0.45, $score, 1e-9);
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMedia(
            id: $id,
            path: __DIR__ . "/novelty-{$id}.jpg",
            takenAt: $takenAt,
        );
    }

}
