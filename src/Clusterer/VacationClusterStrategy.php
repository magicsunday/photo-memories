<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryBuilderInterface;
use MagicSunday\Memories\Clusterer\Contract\HomeLocatorInterface;
use MagicSunday\Memories\Clusterer\Contract\VacationSegmentAssemblerInterface;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;

use function usort;

/**
 * Orchestrates the vacation clustering flow using dedicated collaborators.
 */
final readonly class VacationClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private HomeLocatorInterface $homeLocator,
        private DaySummaryBuilderInterface $daySummaryBuilder,
        private VacationSegmentAssemblerInterface $segmentAssembler,
    ) {
    }

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
        $timestamped = $this->filterTimestampedItems($items);
        if ($timestamped === []) {
            return [];
        }

        usort($timestamped, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $home = $this->homeLocator->determineHome($timestamped);
        if ($home === null) {
            return [];
        }

        $days = $this->daySummaryBuilder->buildDaySummaries($timestamped, $home);
        if ($days === []) {
            return [];
        }

        return $this->segmentAssembler->detectSegments($days, $home);
    }
}
