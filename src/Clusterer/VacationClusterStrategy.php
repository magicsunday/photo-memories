<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\DaySummaryBuilderInterface;
use MagicSunday\Memories\Clusterer\Contract\HomeLocatorInterface;
use MagicSunday\Memories\Clusterer\Contract\VacationSegmentAssemblerInterface;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;

use function usort;

/**
 * Orchestrates the vacation clustering flow using dedicated collaborators.
 *
 * The strategy creates vacation clusters by removing media without
 * timestamps, ordering all remaining captures, inferring the travellers'
 * home location and finally assembling multi-day vacation segments. Each
 * stage is delegated to dedicated services to keep responsibilities focused
 * and composable.
 */
final readonly class VacationClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    /**
     * @param HomeLocatorInterface            $homeLocator       Identifies the home location used as a reference point.
     * @param DaySummaryBuilderInterface      $daySummaryBuilder Builds daily summaries that feed the segment assembler.
     * @param VacationSegmentAssemblerInterface $segmentAssembler Detects high level vacation segments from day summaries.
     */
    public function __construct(
        private HomeLocatorInterface $homeLocator,
        private DaySummaryBuilderInterface $daySummaryBuilder,
        private VacationSegmentAssemblerInterface $segmentAssembler,
    ) {
    }

    /**
     * Returns the identifier of the cluster strategy.
     */
    public function name(): string
    {
        return 'vacation';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // Cluster processing requires reliable timestamps to establish the chronology of the trip.
        $timestamped = $this->filterTimestampedItems($items);
        if ($timestamped === []) {
            return [];
        }

        // Ensure chronological ordering so day summaries receive a consistent sequence.
        usort($timestamped, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        // Determine the traveller's base location, which anchors day grouping and trip detection.
        $home = $this->homeLocator->determineHome($timestamped);
        if ($home === null) {
            return [];
        }

        // Build per-day statistics (distances, stay points, etc.) required to evaluate candidate vacation runs.
        $days = $this->daySummaryBuilder->buildDaySummaries($timestamped, $home);
        if ($days === []) {
            return [];
        }

        // Translate day summaries into scored vacation segments ready for persistence.
        return $this->segmentAssembler->detectSegments($days, $home);
    }
}
